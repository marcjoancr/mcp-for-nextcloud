-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Run with psql. See docs/en/development/official-apps-rehearsal.md.
\set ON_ERROR_STOP on
\if :{?reviewed_plan}
  \set apply true
\else
  \set apply false
  \set reviewed_plan ''
\endif
\if :{?backup_digest}
\else
  \set backup_digest ''
\endif
\if :{?quarantine_held_drafts}
\else
  \set quarantine_held_drafts false
\endif

BEGIN;
SET LOCAL search_path = pg_catalog, pg_temp;
SET LOCAL lock_timeout = '5s';
SET LOCAL TIME ZONE 'UTC';

CREATE FUNCTION pg_temp.mcp_mail_digest(value text) RETURNS text
LANGUAGE sql IMMUTABLE AS $digest$
    SELECT encode(sha256(convert_to(coalesce(value, ''), 'UTF8')), 'hex');
$digest$;

-- Bucket row digests to keep review stable without building one giant payload value.
CREATE FUNCTION pg_temp.mcp_mail_fingerprint(row_query text) RETURNS jsonb LANGUAGE plpgsql AS $fingerprint$
DECLARE
    result jsonb;
BEGIN
    EXECUTE format('SELECT jsonb_build_object(''rows'',coalesce(sum(n),0),
                    ''digest'',pg_temp.mcp_mail_digest(array_agg(digest ORDER BY bucket)::text))
                    FROM (SELECT left(digest,2) AS bucket, count(*) AS n,
                                 pg_temp.mcp_mail_digest(array_agg(digest ORDER BY digest)::text) AS digest
                          FROM (SELECT pg_temp.mcp_mail_digest(value) AS digest FROM (%s) AS source_rows) hashed
                          GROUP BY 1) buckets', row_query) INTO result;
    RETURN result;
END;
$fingerprint$;

CREATE FUNCTION pg_temp.mcp_mail_retire(
    prefix text, quarantine boolean, apply boolean, reviewed_plan text, backup_digest text
) RETURNS jsonb LANGUAGE plpgsql AS $retire$
DECLARE
    archive_name text := prefix || 'mcp_mail_guard_archive';
    guard_names constant text[] := ARRAY[
        'mail_mcp_auth_contexts', 'mail_mcp_auth_states', 'mail_mcp_body_index',
        'mail_mcp_deliveries', 'mail_mcp_draft_copies', 'mail_mcp_erase_accounts',
        'mail_mcp_erasure', 'mail_mcp_sieve', 'mail_mcp_snoozes'
    ];
    guard_oids oid[];
    native_names constant text[] := ARRAY[
        'appconfig', 'mail_accounts', 'mail_attachments', 'mail_local_messages',
        'mail_mailboxes', 'mail_message_tags', 'mail_messages', 'mail_recipients'
    ];
    columns_to_archive constant jsonb := '{
        "mail_accounts":["mcp_auth_revision"],
        "mail_mailboxes":["tag_cache_revision","refresh_snapshot"],
        "mail_messages":["tag_cache_generation","tag_cache_profile"],
        "mail_message_tags":["cached_message_id"],
        "mail_local_messages":["mcp_draft_only"]
    }';
    names text[];
    name text;
    table_name text;
    field text;
    fields jsonb;
    table_oid oid;
    installed_version text;
    native_owner name;
    archive_custody boolean;
    disabled boolean;
    blockers jsonb;
    counts jsonb;
    kept_states jsonb;
    report jsonb;
    plan_digest text;
    held_condition constant text := 'd.mcp_draft_only OR EXISTS (
        SELECT 1 FROM %s x WHERE x.draft_id=d.id AND x.account_id=d.account_id
        AND x.state NOT IN (''blocked'',''cancelled''))';
    held_sql text;
    held_count bigint;
    fingerprint jsonb;
    preserved_hash text := '';
    guard_rows jsonb := '{}';
    hold_recipients bigint;
    hold_attachments bigint;
    exact_tags bigint;
    descriptor record;
    affected bigint;
BEGIN
    IF prefix !~ '^[A-Za-z0-9_]{0,24}$' THEN
        RAISE EXCEPTION 'Invalid Nextcloud table prefix';
    END IF;
    IF (backup_digest <> '' AND backup_digest !~ '^[0-9a-f]{64}$')
       OR (apply AND (reviewed_plan !~ '^[0-9a-f]{64}$' OR backup_digest = '')) THEN
        RAISE EXCEPTION 'Apply needs the reviewed plan digest and matched backup manifest digest';
    END IF;
    SELECT pg_get_userbyid(relowner) INTO native_owner FROM pg_class
     WHERE oid=to_regclass(format('public.%I',prefix || 'appconfig'));
    IF native_owner IS NULL THEN RAISE EXCEPTION 'Nextcloud application configuration table is missing'; END IF;
    archive_custody := native_owner<>current_user AND NOT pg_has_role(native_owner,current_user,'MEMBER');
    IF apply AND NOT archive_custody THEN
        RAISE EXCEPTION 'Use a migration database role separate from the Nextcloud application table owner';
    END IF;
    IF apply THEN
        PERFORM pg_advisory_xact_lock(pg_catalog.hashtextextended('mcp-mail-retire:' || prefix, 0));
    END IF;
    IF EXISTS (SELECT 1 FROM pg_namespace WHERE nspname=archive_name) THEN
        FOREACH name IN ARRAY ARRAY['manifest','detached_foreign_keys','held_drafts','held_attachments',
            'held_recipients','exact_tag_links','mail_accounts_fields','mail_local_messages_fields',
            'mail_mailboxes_fields','mail_messages_fields'] LOOP
            IF to_regclass(format('%I.%I', archive_name, name)) IS NULL THEN
                RAISE EXCEPTION 'Existing Mail retirement archive is incomplete';
            END IF;
        END LOOP;
        FOREACH name IN ARRAY guard_names LOOP
            IF to_regclass(format('%I.%I', archive_name,prefix || name)) IS NULL
               OR to_regclass(format('public.%I',prefix || name)) IS NOT NULL THEN
                RAISE EXCEPTION 'Existing Mail retirement archive does not match the live schema';
            END IF;
        END LOOP;
        EXECUTE format('SELECT jsonb_build_object(''phase'',''archived'',''archive'',%L,
                       ''backup_digest'',backup_digest,''plan'',plan) FROM %I.manifest
                       WHERE version=''5.12.5.13'' AND table_prefix=%L',
                       archive_name, archive_name, prefix) INTO report;
        IF report IS NULL THEN RAISE EXCEPTION 'Archive manifest does not match this retirement'; END IF;
        IF apply AND (report->>'plan' IS DISTINCT FROM reviewed_plan
            OR report->>'backup_digest' IS DISTINCT FROM backup_digest) THEN
            RAISE EXCEPTION 'This archive belongs to a different reviewed plan or backup';
        END IF;
        RETURN report;
    END IF;

    SELECT array_agg(c.oid ORDER BY c.relname), array_agg(substr(c.relname, length(prefix)+1) ORDER BY c.relname)
      INTO guard_oids, names
      FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
     WHERE n.nspname='public' AND c.relkind='r'
       AND left(c.relname, length(prefix || 'mail_mcp_'))=prefix || 'mail_mcp_';
    IF names IS DISTINCT FROM guard_names THEN
        RAISE EXCEPTION 'Unexpected Mail guard table inventory';
    END IF;
    FOREACH name IN ARRAY native_names || guard_names LOOP
        table_oid := to_regclass(format('public.%I', prefix || name));
        IF table_oid IS NULL OR NOT EXISTS (SELECT 1 FROM pg_class WHERE oid=table_oid AND relkind='r') THEN
            RAISE EXCEPTION 'Expected Mail retirement table is missing or unsupported';
        END IF;
    END LOOP;
    IF apply THEN
        -- Refresh metadata after a uniform lock on every source that can affect the reviewed plan.
        SELECT array_agg(format('public.%I', prefix || item) ORDER BY item) INTO names
          FROM unnest(native_names || guard_names) AS item;
        EXECUTE 'LOCK TABLE ' || array_to_string(names, ', ') || ' IN ACCESS EXCLUSIVE MODE';
    END IF;
    EXECUTE format('SELECT max(configvalue) FILTER (WHERE configkey=''installed_version''),
                    max(configvalue) FILTER (WHERE configkey=''enabled'') IS NOT DISTINCT FROM ''no''
                    FROM public.%I WHERE appid=''mail''', prefix || 'appconfig')
       INTO installed_version, disabled;
    IF installed_version IS DISTINCT FROM '5.12.5.13' THEN
        RAISE EXCEPTION 'Mail retirement requires guarded version 5.12.5.13';
    END IF;
    FOR name, fields IN SELECT key, value FROM jsonb_each(columns_to_archive) LOOP
        table_oid := to_regclass(format('public.%I', prefix || name));
        FOR field IN SELECT jsonb_array_elements_text(fields) LOOP
            IF NOT EXISTS (
                SELECT 1 FROM pg_attribute WHERE attrelid=table_oid AND attname=field AND NOT attisdropped
            ) THEN
                RAISE EXCEPTION 'Expected Mail guard column is missing';
            END IF;
        END LOOP;
    END LOOP;
    IF EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE contype='f' AND confrelid=ANY(guard_oids) AND conrelid<>ALL(guard_oids)
    ) THEN
        RAISE EXCEPTION 'A live table has an unexpected reference to a Mail guard table';
    END IF;

    IF EXISTS (
        SELECT 1 FROM pg_constraint c JOIN pg_class parent ON parent.oid=c.confrelid
        JOIN pg_namespace ns ON ns.oid=parent.relnamespace
        WHERE c.contype='f' AND ns.nspname='public' AND parent.relname=ANY(ARRAY[
            prefix || 'mail_attachments', prefix || 'mail_recipients',
            prefix || 'mail_local_messages', prefix || 'mail_message_tags'
        ]) AND c.conrelid<>ALL(guard_oids)
        AND NOT (
            parent.relname=prefix || 'mail_local_messages' AND c.conrelid=ANY(ARRAY[
                to_regclass(format('public.%I',prefix || 'mail_attachments'))::oid,
                to_regclass(format('public.%I',prefix || 'mail_recipients'))::oid
            ])
        )
    ) THEN
        RAISE EXCEPTION 'Unexpected foreign key into a Mail quarantine table';
    END IF;
    IF EXISTS (
        SELECT 1 FROM pg_trigger tr JOIN pg_class t ON t.oid=tr.tgrelid
        JOIN pg_namespace ns ON ns.oid=t.relnamespace
        WHERE NOT tr.tgisinternal AND ns.nspname='public' AND t.relname=ANY(ARRAY[
            prefix || 'mail_attachments', prefix || 'mail_recipients',
            prefix || 'mail_local_messages', prefix || 'mail_message_tags'
        ])
    ) THEN
        RAISE EXCEPTION 'Unexpected trigger on a Mail quarantine table';
    END IF;

    held_sql := format(held_condition, format('public.%I', prefix || 'mail_mcp_deliveries'));
    fingerprint := pg_temp.mcp_mail_fingerprint(format('SELECT to_jsonb(d)::text AS value
                   FROM public.%I d WHERE %s',prefix || 'mail_local_messages',held_sql));
    held_count := (fingerprint->>'rows')::bigint;
    preserved_hash := preserved_hash || (fingerprint->>'digest');
    fingerprint := pg_temp.mcp_mail_fingerprint(format('SELECT to_jsonb(a)::text AS value
                   FROM public.%I a JOIN public.%I d ON d.id=a.local_message_id WHERE %s',
                   prefix || 'mail_attachments',prefix || 'mail_local_messages',held_sql));
    hold_attachments := (fingerprint->>'rows')::bigint;
    preserved_hash := preserved_hash || (fingerprint->>'digest');
    fingerprint := pg_temp.mcp_mail_fingerprint(format('SELECT to_jsonb(r)::text AS value
                   FROM public.%I r JOIN public.%I d ON d.id=r.local_message_id WHERE %s',
                   prefix || 'mail_recipients',prefix || 'mail_local_messages',held_sql));
    hold_recipients := (fingerprint->>'rows')::bigint;
    preserved_hash := preserved_hash || (fingerprint->>'digest');
    fingerprint := pg_temp.mcp_mail_fingerprint(format('SELECT to_jsonb(t)::text AS value
                   FROM public.%I t WHERE cached_message_id IS NOT NULL',prefix || 'mail_message_tags'));
    exact_tags := (fingerprint->>'rows')::bigint;
    preserved_hash := preserved_hash || (fingerprint->>'digest');
    FOREACH name IN ARRAY guard_names LOOP
        fingerprint := pg_temp.mcp_mail_fingerprint(format('SELECT to_jsonb(t)::text AS value FROM public.%I t',prefix || name));
        guard_rows := guard_rows || jsonb_build_object(name,fingerprint->'rows');
        preserved_hash := preserved_hash || (fingerprint->>'digest');
    END LOOP;
    FOR name, fields IN SELECT key, value FROM jsonb_each(columns_to_archive) WHERE key <> 'mail_message_tags' ORDER BY key LOOP
        SELECT string_agg(format('%I', value), ', ' ORDER BY value) INTO field FROM jsonb_array_elements_text(fields);
        fingerprint := pg_temp.mcp_mail_fingerprint(format('SELECT to_jsonb(t)::text AS value
                       FROM (SELECT id,%s FROM public.%I) t',field,prefix || name));
        preserved_hash := preserved_hash || (fingerprint->>'digest');
    END LOOP;
    EXECUTE format('SELECT jsonb_build_object(
        ''native_outbox'', (SELECT count(*) FROM public.%I WHERE type=0
            AND (coalesce(status,-1) NOT IN (12,13) OR (send_at IS NOT NULL AND status IS DISTINCT FROM 13))),
        ''pending_deliveries'', (SELECT count(*) FROM public.%I WHERE state IN (''queued'',''attempting'')
                                  OR sent_state IN (''pending'',''attempting'')),
        ''active_snoozes'', (SELECT count(*) FROM public.%I WHERE state NOT IN (''blocked'',''cancelled'',''woken'')
                    OR active_source IS NOT NULL
                    OR placement_phase NOT IN (''prepared'',''remote_verified'',''cache_pending'',''completed'',''failed_before_effect'')
                    OR wake_phase NOT IN (''prepared'',''remote_verified'',''cache_pending'',''completed'',''failed_before_effect'')),
        ''pending_account_erasure'', (SELECT count(*) FROM public.%I e WHERE e.state IN (''prepared'',''pending'')
            AND EXISTS (SELECT 1 FROM public.%I x WHERE x.erasure_id=e.id)),
        ''unexpected_held_rows'', (SELECT count(*) FROM public.%I d WHERE (%s)
                                  AND (type IS DISTINCT FROM 1 OR send_at IS NOT NULL)),
        ''held_drafts_need_consent'', %s
      )', prefix || 'mail_local_messages', prefix || 'mail_mcp_deliveries',
           prefix || 'mail_mcp_snoozes', prefix || 'mail_mcp_erasure', prefix || 'mail_mcp_erase_accounts',
           prefix || 'mail_local_messages', held_sql, CASE WHEN quarantine THEN 0 ELSE held_count END) INTO blockers;
    EXECUTE format('SELECT coalesce(jsonb_object_agg(delivery_state,n), ''{}''::jsonb) FROM
                    (SELECT state || ''/'' || sent_state AS delivery_state, count(*) AS n
                     FROM public.%I GROUP BY 1) states', prefix || 'mail_mcp_deliveries') INTO kept_states;
    counts := jsonb_build_object('held_drafts',held_count,'held_recipients',hold_recipients,
                                 'held_attachments',hold_attachments,'exact_tag_links',exact_tags);
    report := jsonb_build_object(
        'phase','review','database',current_database(),'archive',archive_name,'version',installed_version,
        'mail_disabled',disabled,'quarantine_held_drafts',quarantine,'counts',counts,
        'blockers',blockers,'delivery_states',kept_states,'guard_rows',guard_rows,
        'archive_custody',archive_custody,'custodian',current_user,'backup_digest',nullif(backup_digest,''),'ready',
        disabled AND archive_custody AND backup_digest<>'' AND NOT EXISTS (SELECT 1 FROM jsonb_each_text(blockers) WHERE value::bigint<>0)
    );
    plan_digest := pg_temp.mcp_mail_digest(report::text || preserved_hash);
    report := report || jsonb_build_object('plan',plan_digest);
    IF NOT apply THEN RETURN report; END IF;
    IF reviewed_plan IS DISTINCT FROM plan_digest THEN
        RAISE EXCEPTION 'Mail retirement plan changed. Review the current inventory again';
    END IF;
    IF NOT (report->>'ready')::boolean THEN
        RAISE EXCEPTION 'Mail retirement is blocked. Keep Mail disabled and review the inventory';
    END IF;

    -- Preserve both sides of any known relationship before the archive is detached.
    EXECUTE format('CREATE SCHEMA %I', archive_name);
    EXECUTE format('REVOKE ALL ON SCHEMA %I FROM PUBLIC', archive_name);
    FOR descriptor IN
        SELECT DISTINCT pg_get_userbyid(acl.grantee) AS grantee
          FROM pg_namespace n CROSS JOIN LATERAL aclexplode(n.nspacl) acl
         WHERE n.nspname=archive_name AND acl.grantee NOT IN (0,n.nspowner)
    LOOP
        EXECUTE format('REVOKE ALL ON SCHEMA %I FROM %I',archive_name,descriptor.grantee);
    END LOOP;
    IF has_schema_privilege(native_owner,archive_name,'USAGE')
       OR has_schema_privilege(native_owner,archive_name,'CREATE') THEN
        RAISE EXCEPTION 'The Nextcloud application table owner can reach the archive';
    END IF;
    EXECUTE format('CREATE TABLE %I.manifest (version text NOT NULL, table_prefix text PRIMARY KEY, plan text NOT NULL,
                    backup_digest text, review jsonb, archived_at timestamptz)', archive_name);
    EXECUTE format('INSERT INTO %I.manifest VALUES ($1,$2,$3,$4,$5,clock_timestamp())', archive_name)
        USING installed_version, prefix, plan_digest, backup_digest, report;
    EXECUTE format('CREATE TABLE %I.detached_foreign_keys (
                    child_table text, parent_table text, constraint_name text, definition text)', archive_name);
    FOR descriptor IN
        SELECT c.conname, source.relname AS child_table, target.relname AS parent_table,
               pg_get_constraintdef(c.oid) AS definition
          FROM pg_constraint c JOIN pg_class source ON source.oid=c.conrelid
          JOIN pg_class target ON target.oid=c.confrelid
         WHERE contype='f' AND conrelid=ANY(guard_oids) AND confrelid<>ALL(guard_oids)
         ORDER BY source.relname,c.conname
    LOOP
        IF NOT (
            (descriptor.child_table=prefix || 'mail_mcp_body_index' AND descriptor.parent_table=ANY(ARRAY[
                prefix || 'mail_accounts',prefix || 'mail_mailboxes',prefix || 'mail_messages'
            ])) OR (descriptor.child_table=prefix || 'mail_mcp_draft_copies'
                AND descriptor.parent_table=ANY(ARRAY[prefix || 'mail_accounts',prefix || 'mail_local_messages']))
            OR (descriptor.child_table=prefix || 'mail_mcp_auth_contexts'
                AND descriptor.parent_table=prefix || 'mail_accounts')
        ) THEN
            RAISE EXCEPTION 'Unexpected foreign key from a Mail guard table';
        END IF;
        EXECUTE format('INSERT INTO %I.detached_foreign_keys VALUES ($1,$2,$3,$4)', archive_name)
            USING descriptor.child_table, descriptor.parent_table, descriptor.conname, descriptor.definition;
        EXECUTE format('ALTER TABLE public.%I DROP CONSTRAINT %I', descriptor.child_table, descriptor.conname);
    END LOOP;
    EXECUTE format('CREATE TABLE %I.held_drafts AS SELECT d.* FROM public.%I d WHERE %s',
                   archive_name, prefix || 'mail_local_messages', held_sql);
    EXECUTE format('CREATE TABLE %I.held_recipients AS SELECT r.* FROM public.%I r
                    JOIN %I.held_drafts d ON d.id=r.local_message_id',
                   archive_name, prefix || 'mail_recipients', archive_name);
    EXECUTE format('CREATE TABLE %I.held_attachments AS SELECT a.* FROM public.%I a
                    JOIN %I.held_drafts d ON d.id=a.local_message_id',
                   archive_name, prefix || 'mail_attachments', archive_name);
    EXECUTE format('CREATE TABLE %I.exact_tag_links AS SELECT * FROM public.%I WHERE cached_message_id IS NOT NULL',
                   archive_name, prefix || 'mail_message_tags');

    -- Keep the seven guard attributes of live rows in addition to complete quarantined rows.
    FOR name, fields IN SELECT key, value FROM jsonb_each(columns_to_archive) WHERE key <> 'mail_message_tags' LOOP
        SELECT string_agg(format('%I', value), ', ' ORDER BY value) INTO field FROM jsonb_array_elements_text(fields);
        EXECUTE format('CREATE TABLE %I.%I AS SELECT id, %s FROM public.%I',
                       archive_name, name || '_fields', field, prefix || name);
    END LOOP;

    IF held_count>0 THEN
        FOREACH name IN ARRAY ARRAY['mail_attachments','mail_recipients','mail_local_messages'] LOOP
            table_name := CASE WHEN name='mail_local_messages' THEN 'id' ELSE 'local_message_id' END;
            EXECUTE format('DELETE FROM public.%I t USING %I.held_drafts d WHERE t.%I=d.id',
                           prefix || name, archive_name, table_name);
            GET DIAGNOSTICS affected = ROW_COUNT;
            IF affected <> (CASE name WHEN 'mail_attachments' THEN hold_attachments
                                    WHEN 'mail_recipients' THEN hold_recipients ELSE held_count END) THEN
                RAISE EXCEPTION 'Unexpected row count during Mail draft quarantine';
            END IF;
        END LOOP;
    END IF;
    EXECUTE format('DELETE FROM public.%I WHERE cached_message_id IS NOT NULL', prefix || 'mail_message_tags');
    GET DIAGNOSTICS affected = ROW_COUNT;
    IF affected <> exact_tags THEN RAISE EXCEPTION 'Exact tag associations changed before retirement'; END IF;

    FOR name, fields IN SELECT key,value FROM jsonb_each(columns_to_archive) LOOP
        SELECT string_agg(format('DROP COLUMN %I', value), ', ' ORDER BY value)
          INTO field FROM jsonb_array_elements_text(fields);
        EXECUTE format('ALTER TABLE public.%I %s', prefix || name, field);
    END LOOP;
    FOREACH name IN ARRAY guard_names LOOP
        EXECUTE format('ALTER TABLE public.%I SET SCHEMA %I', prefix || name, archive_name);
        EXECUTE format('ALTER TABLE %I.%I OWNER TO %I',archive_name,prefix || name,current_user);
    END LOOP;
    RETURN report || jsonb_build_object('phase','archived','backup_digest',backup_digest);
END;
$retire$;

SELECT pg_temp.mcp_mail_retire(:'prefix', :'quarantine_held_drafts'::boolean,
                              :'apply'::boolean, :'reviewed_plan', :'backup_digest') AS result \gset
\if :apply
  COMMIT;
\else
  ROLLBACK;
\endif
\echo :result
