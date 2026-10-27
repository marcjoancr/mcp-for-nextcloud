#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The Mail part of an integration cell, only when MCP_IT_WITH_MAIL=1: a throwaway IMAP server (the image pinned below,
# on the run's own network, with an anonymous volume removed by the label-guarded runner), the Nextcloud Mail release pinned per
# Nextcloud major, and one Mail account for each of two fixture users, configured through the Mail app's own occ
# commands and synchronized once. A cell without MCP_IT_WITH_MAIL never pulls the image nor installs Mail.
#
# Usage: mail-cell.sh <provision|collect>
#   provision  starts and seeds the IMAP server, installs and enables Mail, creates and synchronizes the accounts
#   collect    copies the IMAP server log into the artifacts
# Environment: the harness variables (MCP_IT_NC, MCP_IT_NET, MCP_IT_NC_NAME, RUNNER_TEMP) and MCP_IT_IMAP_NAME, the name
# run-local.sh gives this run's IMAP container.
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
: "${MCP_IT_LOCAL:?}" "${MCP_IT_RUN_ID:?}" "${MCP_IT_NET:?}" "${MCP_IT_NC_NAME:?}" "${MCP_IT_IMAP_NAME:?}" "${RUNNER_TEMP:?}"
[ "$MCP_IT_LOCAL" = 1 ] && [ "${MCP_IT_WITH_MAIL:-}" = 1 ] || exit 2
NET=$MCP_IT_NET
NC=$MCP_IT_NC_NAME
IMAP=$MCP_IT_IMAP_NAME
WORK="$RUNNER_TEMP/mcp-it"
TOOLS_DIR="$WORK/tools"
ARTIFACTS="$WORK/artifacts"
# shellcheck disable=SC2016
pin() { php -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $p[$argv[2]];' "$HERE/mail-pins.json" "$1"; }
IMAP_IMAGE=$(pin imap_image)
MAIL_TAG=v$(pin mail_version)
MAIL_SHA=$(pin mail_sha256)
IMAP_PORT=31143
# Public fixture-only password, never reused outside the disposable network.
IMAP_PASSWORD=mcp-it-imap-password
assert_owner() {
    owner=$(docker inspect --format '{{ index .Config.Labels "mcp.integration.run" }}' "$1")
    [ "$owner" = "$MCP_IT_RUN_ID" ] || { echo "Refusing unrelated Mail fixture container" >&2; exit 2; }
}
# Provisioning is allowed only inside this run's owned Nextcloud/network.
if [ "${1:-}" = provision ]; then
    assert_owner "$NC"
    owner=$(docker network inspect --format '{{ index .Labels "mcp.integration.run" }}' "$NET")
    [ "$owner" = "$MCP_IT_RUN_ID" ] || exit 2
fi

# occ <args...>: the server's command line, as the web server user.
occ() {
	docker exec -u www-data "$NC" php /var/www/html/occ "$@"
}

# sha256_check: checks "<sha256>  <file>" on stdin with GNU coreutils or the BSD tools.
sha256_check() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum -c -
	else
		shasum -a 256 -c -
	fi
}

# imap_ready: succeeds once the IMAP listener accepts connections from the Nextcloud container.
imap_ready() {
	docker exec "$NC" php -r '$s = @fsockopen($argv[1], (int)$argv[2], $n, $m, 2); exit($s ? 0 : 1);' "$IMAP" "$IMAP_PORT"
}

# message <from> <subject>: one small RFC 5322 message on stdout.
message() {
	printf 'From: %s\r\nTo: mcp-it@example.com\r\nSubject: %s\r\nDate: Sat, 24 Oct 2026 10:00:00 +0000\r\nMessage-ID: <%s@mcp-it.example>\r\n\r\nBody of %s.\r\n' \
		"$1" "$2" "$(printf '%s' "$2" | tr ' ' '-')" "$2"
}

# save <imap user> <mailbox> <subject>: stores one message through doveadm, the server's own tool.
save() {
	message "sender@example.com" "$3" | docker exec -i "$IMAP" doveadm save -u "$1" -m "$2"
}

# start_imap: the IMAP server of this run, seeded with the folders and messages the tests expect.
# alice: INBOX (3 messages, the first seen, the second flagged), Sent (1 seen), Archive (1), Projects, Projects/2026.
# bob: INBOX (1 message); bob's account is the one alice must never reach.
start_imap() {
	docker pull --quiet "$IMAP_IMAGE" >/dev/null
	docker run -d --name "$IMAP" --label "mcp.integration.run=$MCP_IT_RUN_ID" --network "$NET" -e USER_PASSWORD="$IMAP_PASSWORD" \
		-v "$HERE/mail/mcp-it.conf:/etc/dovecot/conf.d/mcp-it.conf:ro" "$IMAP_IMAGE" >/dev/null
	deadline=$(($(date +%s) + 120))
	until imap_ready >/dev/null 2>&1; do
		if [ "$(date +%s)" -ge "$deadline" ]; then
			assert_owner "$IMAP"
	docker logs "$IMAP" >&2 || true
			echo "Timed out waiting for the IMAP server" >&2
			return 1
		fi
		sleep 2
	done
	docker exec "$IMAP" doveadm mailbox create -u alice Sent Archive Projects Projects/2026
	save alice INBOX "Seen message"
	save alice INBOX "Flagged message"
	save alice INBOX "Unread message"
	save alice Sent "Sent message"
	save alice Archive "Archived message"
	save bob INBOX "Message of bob"
	docker exec "$IMAP" doveadm flags add -u alice '\Seen' mailbox INBOX uid 1
	docker exec "$IMAP" doveadm flags add -u alice '\Flagged' mailbox INBOX uid 2
	docker exec "$IMAP" doveadm flags add -u alice '\Seen' mailbox Sent uid 1
	echo "IMAP server $IMAP_IMAGE -> $(docker image inspect --format '{{.Os}}/{{.Architecture}} {{.Size}} bytes' "$IMAP_IMAGE")"
}

# install_mail: the pinned Mail release, checked against its checksum, unpacked into custom_apps and enabled.
install_mail() {
	mkdir -p "$TOOLS_DIR"
	archive="$TOOLS_DIR/mail.tar.gz"
	curl -fsSL -o "$archive" "https://github.com/nextcloud-releases/mail/releases/download/$MAIL_TAG/mail-$MAIL_TAG.tar.gz"
	echo "$MAIL_SHA  $archive" | sha256_check
	# The archive is public source; www-data must read it across the tools mount.
	chmod 0644 "$archive"
	docker exec -u www-data "$NC" tar -xzf /mcp-it-tools/mail.tar.gz -C /var/www/html/custom_apps
	occ app:enable mail
	actual=$(occ config:app:get mail installed_version)
	[ "$actual" = "${MAIL_TAG#v}" ] || { echo "Unexpected installed Mail $actual" >&2; return 1; }
	echo "Mail $actual enabled"
}

# create_account <uid> <imap user>: one account through the Mail app's occ command, synchronized once; prints its id.
create_account() {
	created=$(occ mail:account:create-imap "$1" "Mail of $2" "$2@example.com" "$IMAP" "$IMAP_PORT" none "$2" "$IMAP_PASSWORD" \
		"$IMAP" 31587 none "$2" "$IMAP_PASSWORD")
	id=$(printf '%s\n' "$created" | sed -n 's/^Account \([0-9][0-9]*\) for .*/\1/p')
	case "$id" in
		''|*[!0-9]*) echo "mail:account:create-imap gave no account id: '$created'" >&2; return 1 ;;
	esac
	occ mail:account:sync "$id" >&2
	echo "$id"
}

# cmd_provision: IMAP server, Mail app and the two accounts.
cmd_provision() {
	start_imap
	install_mail
	# The IMAP server has a private address on the run's network; Nextcloud refuses local remote hosts by default.
	occ config:system:set allow_local_remote_servers --value=true --type=boolean
	alice=$(create_account mcp-it-alice alice)
	bob=$(create_account mcp-it-bob bob)
	echo "Mail accounts: mcp-it-alice=$alice mcp-it-bob=$bob"
}

# cmd_collect: the IMAP server log, which lists every login and every flag change (mail_log), into the artifacts.
cmd_collect() {
	mkdir -p "$ARTIFACTS"
	assert_owner "$IMAP"
	docker logs "$IMAP" > "$ARTIFACTS/imap-container.log" 2>&1 || true
}

# Cleanup is owned by run-local.sh and guarded by this run's resource label.
case "${1:-}" in
	provision) cmd_provision ;;
	collect) cmd_collect ;;
	*)
		echo "Usage: $0 <provision|collect>" >&2
		exit 1
		;;
esac
