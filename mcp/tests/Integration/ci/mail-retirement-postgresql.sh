#!/bin/sh
# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu

HERE=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH='' cd -- "$HERE/../../../.." && pwd)
IMAGE='postgres:16.15@sha256:b95b87e93ceb7594715d5c71fb6e09f6fc878851ab537735d617325eb9ba8b1b'
RUN_ID="mcp-mail-retirement-$(date -u +%Y%m%d%H%M%S)-$(php -r 'echo bin2hex(random_bytes(5));')"
DB_NAME="$RUN_ID-pg"
LABEL='mcp.mail-retirement.run'
OUT=${MCP_IT_RETIREMENT_EVIDENCE:-"$ROOT/mcp/build/integration/$RUN_ID"}
DUMP='/var/lib/postgresql/data/mail-retirement-baseline.dump'
MANIFEST='/var/lib/postgresql/data/mail-retirement-baseline.manifest'
mkdir -p "$OUT"
chmod 0700 "$OUT"
DB_PASSWORD=$(openssl rand -hex 24)
DB_PASSWORD=${DB_PASSWORD:?}

owned() {
    [ "$(docker inspect --format "{{ index .Config.Labels \"$LABEL\" }}" "$1" 2>/dev/null || true)" = "$RUN_ID" ]
}
cleanup() {
    result=$?
    trap - EXIT HUP INT TERM
    if owned "$DB_NAME"; then docker rm -fv "$DB_NAME" >/dev/null || result=1; fi
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' HUP TERM
if docker inspect "$DB_NAME" >/dev/null 2>&1; then
    echo "Refusing pre-existing retirement fixture: $DB_NAME" >&2
    exit 2
fi

docker run -d --name "$DB_NAME" --label "$LABEL=$RUN_ID" --network none \
    -e POSTGRES_DB=retirement_rehearsal -e POSTGRES_USER=nextcloud -e POSTGRES_PASSWORD="$DB_PASSWORD" "$IMAGE" >/dev/null
unset DB_PASSWORD
attempt=0
until docker exec "$DB_NAME" pg_isready -U nextcloud -d retirement_rehearsal >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    [ "$attempt" -lt 60 ] || { echo 'Timed out waiting for the synthetic PostgreSQL fixture' >&2; exit 1; }
    sleep 1
done
[ "$(docker inspect --format '{{.HostConfig.NetworkMode}} {{len .HostConfig.PortBindings}}' "$DB_NAME")" = 'none 0' ]
docker cp "$HERE/mail-retirement-fixture.sql" "$DB_NAME:/tmp/mail-retirement-fixture.sql" >/dev/null
docker exec "$DB_NAME" psql -X -v ON_ERROR_STOP=1 -U nextcloud -d retirement_rehearsal -f /tmp/mail-retirement-fixture.sql >/dev/null

snapshot_state() {
    docker exec "$DB_NAME" psql -X -qAt -v ON_ERROR_STOP=1 -U nextcloud -d retirement_rehearsal -c "
        SELECT jsonb_build_object(
          'version',(SELECT max(configvalue) FROM oc_appconfig WHERE appid='mail' AND configkey='installed_version'),
          'enabled',(SELECT max(configvalue) FROM oc_appconfig WHERE appid='mail' AND configkey='enabled'),
          'guard_tables',(SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'oc_mail_mcp_%'),
          'protected_drafts',(SELECT count(*) FROM oc_mail_local_messages WHERE type=1 AND failed AND send_at IS NULL AND mcp_draft_only),
          'recipients',(SELECT count(*) FROM oc_mail_recipients r JOIN oc_mail_local_messages d ON d.id=r.local_message_id WHERE d.mcp_draft_only),
          'attachments',(SELECT count(*) FROM oc_mail_attachments a JOIN oc_mail_local_messages d ON d.id=a.local_message_id WHERE d.mcp_draft_only),
          'uncertain_deliveries',(SELECT count(*) FROM oc_mail_mcp_deliveries WHERE state='uncertain' AND sent_state='not_started'),
          'exact_tags',(SELECT count(*) FROM oc_mail_message_tags WHERE cached_message_id IS NOT NULL),
          'legacy_tags',(SELECT count(*) FROM oc_mail_message_tags WHERE cached_message_id IS NULL),
          'guard_column',EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='oc_mail_local_messages' AND column_name='mcp_draft_only'),
          'owner',(SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid='oc_appconfig'::regclass))"
}

backup_state=$(snapshot_state)
printf '%s\n' "$backup_state" > "$OUT/baseline-state.json"
docker exec "$DB_NAME" pg_dump -Fc -U nextcloud -d retirement_rehearsal -f "$DUMP"
dump_sha=$(docker exec "$DB_NAME" sha256sum "$DUMP" | awk '{print $1}')
docker exec "$DB_NAME" sh -c "printf 'database=retirement_rehearsal\\npostgres=16.15\\ndump_sha256=%s\\n' '$dump_sha' > '$MANIFEST'"
backup_digest=$(docker exec "$DB_NAME" sha256sum "$MANIFEST" | awk '{print $1}')
printf '%s\n' "$dump_sha" > "$OUT/baseline-dump.sha256"
printf '%s\n' "$backup_digest" > "$OUT/backup-manifest.sha256"

preview() {
    quarantine=$1
    docker exec -i "$DB_NAME" psql -X -qAt -v ON_ERROR_STOP=1 -U nextcloud -d retirement_rehearsal \
      -v prefix=oc_ -v quarantine_held_drafts="$quarantine" -v backup_digest="$backup_digest" \
      -f - < "$ROOT/native/mail-guard/retire-postgresql.sql"
}
assert_review() {
    python3 - "$1" "$2" "$3" "$4" "$5" <<'PY'
import json, sys
report = json.loads(sys.argv[1])
expected_ready, native, pending, consent = sys.argv[2:]
assert report['ready'] is (expected_ready == 'true'), report
assert report['blockers']['native_outbox'] == int(native), report
assert report['blockers']['pending_deliveries'] == int(pending), report
assert report['blockers']['held_drafts_need_consent'] == int(consent), report
assert report['mail_disabled'] is True and report['archive_custody'] is True, report
PY
}

without_consent=$(preview false)
assert_review "$without_consent" false 0 0 1
[ "$(snapshot_state)" = "$backup_state" ]

# Exercise both independent queue blockers, then return the seed to its reviewed state.
docker exec "$DB_NAME" psql -X -v ON_ERROR_STOP=1 -U nextcloud -d retirement_rehearsal -c \
  "INSERT INTO oc_mail_local_messages VALUES (2,0,1,NULL,'native queue blocker',false,0,false); UPDATE oc_mail_mcp_deliveries SET state='queued' WHERE id='delivery-1';" >/dev/null
blocked=$(preview true)
assert_review "$blocked" false 1 1 0
docker exec "$DB_NAME" psql -X -v ON_ERROR_STOP=1 -U nextcloud -d retirement_rehearsal -c \
  "DELETE FROM oc_mail_local_messages WHERE id=2; UPDATE oc_mail_mcp_deliveries SET state='uncertain' WHERE id='delivery-1';" >/dev/null
[ "$(snapshot_state)" = "$backup_state" ]

ready=$(preview true)
assert_review "$ready" true 0 0 0
python3 - "$ready" <<'PY'
import json, sys
report=json.loads(sys.argv[1])
assert report['counts'] == {'held_drafts': 1, 'held_recipients': 1, 'held_attachments': 1, 'exact_tag_links': 1}, report
assert report['delivery_states'] == {'uncertain/not_started': 1}, report
PY
# PHP code is a literal argument here; it reads only the synthetic preview JSON from stdin.
# shellcheck disable=SC2016
plan=$(printf '%s' "$ready" | php -r '$v=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo $v["plan"];')
printf '%s\n' "$ready" > "$OUT/ready-preview.json"

# A changed protected row must invalidate the reviewed plan and leave the archive absent.
docker exec "$DB_NAME" psql -X -v ON_ERROR_STOP=1 -U nextcloud -d retirement_rehearsal -c \
  "UPDATE oc_mail_local_messages SET subject='changed after review' WHERE id=1" >/dev/null
set +e
docker exec -i "$DB_NAME" psql -X -v ON_ERROR_STOP=1 -U nextcloud -d retirement_rehearsal \
  -v prefix=oc_ -v quarantine_held_drafts=true -v reviewed_plan="$plan" -v backup_digest="$backup_digest" \
  -f - < "$ROOT/native/mail-guard/retire-postgresql.sql" > "$OUT/stale-plan.log" 2>&1
stale_status=$?
set -e
[ "$stale_status" -ne 0 ]
grep -q 'Mail retirement plan changed' "$OUT/stale-plan.log"
[ "$(docker exec "$DB_NAME" psql -X -qAt -U nextcloud -d retirement_rehearsal -c "SELECT to_regnamespace('oc_mcp_mail_guard_archive') IS NULL")" = t ]
docker exec "$DB_NAME" psql -X -v ON_ERROR_STOP=1 -U nextcloud -d retirement_rehearsal -c \
  "UPDATE oc_mail_local_messages SET subject='protected fixture draft' WHERE id=1" >/dev/null

ready=$(preview true)
assert_review "$ready" true 0 0 0
# PHP code is a literal argument here; it reads only the synthetic preview JSON from stdin.
# shellcheck disable=SC2016
plan=$(printf '%s' "$ready" | php -r '$v=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo $v["plan"];')
printf '%s\n' "$ready" > "$OUT/apply-preview.json"
apply_result=$(docker exec -i "$DB_NAME" psql -X -qAt -v ON_ERROR_STOP=1 -U nextcloud -d retirement_rehearsal \
  -v prefix=oc_ -v quarantine_held_drafts=true -v reviewed_plan="$plan" -v backup_digest="$backup_digest" \
  -f - < "$ROOT/native/mail-guard/retire-postgresql.sql")
printf '%s\n' "$apply_result" > "$OUT/apply-result.json"

verified=$(docker exec "$DB_NAME" psql -X -qAt -v ON_ERROR_STOP=1 -U nextcloud -d retirement_rehearsal -c "
    SELECT jsonb_build_object(
      'archive_tables',(SELECT count(*) FROM pg_tables WHERE schemaname='oc_mcp_mail_guard_archive'),
      'guard_tables_archived',(SELECT count(*) FROM pg_tables WHERE schemaname='oc_mcp_mail_guard_archive' AND tablename LIKE 'oc_mail_mcp_%'),
      'held_drafts',(SELECT count(*) FROM oc_mcp_mail_guard_archive.held_drafts),
      'held_recipients',(SELECT count(*) FROM oc_mcp_mail_guard_archive.held_recipients),
      'held_attachments',(SELECT count(*) FROM oc_mcp_mail_guard_archive.held_attachments),
      'uncertain_deliveries',(SELECT count(*) FROM oc_mcp_mail_guard_archive.oc_mail_mcp_deliveries WHERE state='uncertain' AND sent_state='not_started'),
      'exact_tags_archived',(SELECT count(*) FROM oc_mcp_mail_guard_archive.exact_tag_links),
      'legacy_tags_live',(SELECT count(*) FROM oc_mail_message_tags WHERE imap_message_id='legacy-wire-link'),
      'public_guard_tables',(SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'oc_mail_mcp_%'),
      'live_held_drafts',(SELECT count(*) FROM oc_mail_local_messages),
      'guard_column_dropped',NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='oc_mail_local_messages' AND column_name='mcp_draft_only'),
      'archive_denied_to_native_owner',NOT has_schema_privilege('oc_admin','oc_mcp_mail_guard_archive','USAGE'),
      'manifest_matches',(SELECT plan='$plan' AND backup_digest='$backup_digest' FROM oc_mcp_mail_guard_archive.manifest WHERE version='5.12.5.13' AND table_prefix='oc_'))")
printf '%s\n' "$verified" > "$OUT/apply-verified.json"
python3 - "$verified" <<'PY'
import json, sys
v=json.loads(sys.argv[1])
for key in ('held_drafts','held_recipients','held_attachments','uncertain_deliveries','exact_tags_archived','legacy_tags_live','guard_column_dropped','archive_denied_to_native_owner','manifest_matches'):
    assert v[key] == 1 if key in ('held_drafts','held_recipients','held_attachments','uncertain_deliveries','exact_tags_archived','legacy_tags_live') else v[key] is True, v
assert v['guard_tables_archived'] == 9 and v['public_guard_tables'] == 0 and v['live_held_drafts'] == 0, v
PY

idempotent=$(docker exec -i "$DB_NAME" psql -X -qAt -v ON_ERROR_STOP=1 -U nextcloud -d retirement_rehearsal \
  -v prefix=oc_ -v quarantine_held_drafts=true -v reviewed_plan="$plan" -v backup_digest="$backup_digest" \
  -f - < "$ROOT/native/mail-guard/retire-postgresql.sql")
printf '%s\n' "$idempotent" > "$OUT/idempotent-result.json"
python3 - "$idempotent" <<'PY'
import json, sys
report=json.loads(sys.argv[1])
assert report['phase']=='archived', report
PY

# Matched restore from the exact dump and manifest used for the reviewed apply.
docker exec "$DB_NAME" psql -X -v ON_ERROR_STOP=1 -U nextcloud -d postgres -c 'DROP DATABASE retirement_rehearsal' >/dev/null
docker exec "$DB_NAME" createdb -U nextcloud retirement_rehearsal
docker exec "$DB_NAME" pg_restore -e -U nextcloud -d retirement_rehearsal "$DUMP"
restored=$(snapshot_state)
printf '%s\n' "$restored" > "$OUT/rollback-state.json"
[ "$restored" = "$backup_state" ]

sql_sha=$(sha256sum "$ROOT/native/mail-guard/retire-postgresql.sql" | awk '{print $1}')
printf '{"profile":"official-apps-nc35-pg16-retirement","postgres":"16.15","sql_sha256":"%s","backup_manifest_sha256":"%s","review_plan_sha256":"%s","preview_nonmutating":true,"native_outbox_blocked":true,"queued_delivery_blocked":true,"consent_required":true,"stale_plan_rejected":true,"archive_verified":true,"idempotent":true,"matched_restore":true}\n' \
  "$sql_sha" "$backup_digest" "$plan" > "$OUT/summary.json"
printf 'Mail retirement PostgreSQL rehearsal passed: %s\n' "$OUT/summary.json"
