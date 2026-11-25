-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
-- SPDX-License-Identifier: AGPL-3.0-or-later
CREATE ROLE oc_admin NOLOGIN;

CREATE TABLE oc_appconfig (
    appid text NOT NULL,
    configkey text NOT NULL,
    configvalue text,
    PRIMARY KEY (appid, configkey)
);
CREATE TABLE oc_migrations (app text NOT NULL, version text NOT NULL, PRIMARY KEY (app, version));
CREATE TABLE oc_mail_accounts (id integer PRIMARY KEY, mcp_auth_revision bigint NOT NULL);
CREATE TABLE oc_mail_mailboxes (id integer PRIMARY KEY, account_id integer NOT NULL, tag_cache_revision bigint NOT NULL, refresh_snapshot text);
CREATE TABLE oc_mail_messages (id bigint PRIMARY KEY, tag_cache_generation text, tag_cache_profile text);
CREATE TABLE oc_mail_local_messages (
    id integer PRIMARY KEY,
    type integer NOT NULL,
    account_id integer NOT NULL REFERENCES oc_mail_accounts(id),
    send_at integer,
    subject text NOT NULL,
    failed boolean NOT NULL,
    status integer NOT NULL,
    mcp_draft_only boolean NOT NULL
);
CREATE TABLE oc_mail_attachments (
    id integer PRIMARY KEY,
    local_message_id integer REFERENCES oc_mail_local_messages(id) ON DELETE CASCADE,
    file_name text NOT NULL
);
CREATE TABLE oc_mail_recipients (
    id bigint PRIMARY KEY,
    local_message_id integer REFERENCES oc_mail_local_messages(id) ON DELETE CASCADE,
    email text NOT NULL
);
CREATE TABLE oc_mail_message_tags (
    id integer PRIMARY KEY,
    cached_message_id bigint REFERENCES oc_mail_messages(id) ON DELETE CASCADE,
    imap_message_id text NOT NULL,
    tag_id integer NOT NULL
);

CREATE TABLE oc_mail_mcp_auth_contexts (
    id text PRIMARY KEY,
    account_id integer NOT NULL REFERENCES oc_mail_accounts(id)
);
CREATE TABLE oc_mail_mcp_auth_states (
    id text PRIMARY KEY,
    context_id text NOT NULL REFERENCES oc_mail_mcp_auth_contexts(id) ON DELETE CASCADE
);
CREATE TABLE oc_mail_mcp_body_index (
    id integer PRIMARY KEY,
    account_id integer NOT NULL REFERENCES oc_mail_accounts(id) ON DELETE CASCADE,
    mailbox_id integer NOT NULL REFERENCES oc_mail_mailboxes(id) ON DELETE CASCADE,
    message_id bigint NOT NULL REFERENCES oc_mail_messages(id) ON DELETE CASCADE
);
CREATE TABLE oc_mail_mcp_deliveries (
    id text PRIMARY KEY,
    user_id text NOT NULL,
    operation_key text NOT NULL,
    revision text NOT NULL,
    content_revision text NOT NULL,
    state text NOT NULL,
    sent_state text NOT NULL,
    source_tool text NOT NULL,
    timezone text NOT NULL,
    draft_id bigint NOT NULL,
    account_id bigint NOT NULL,
    connection_id bigint NOT NULL,
    send_at bigint NOT NULL,
    created_at bigint NOT NULL,
    alias_id bigint,
    artifact text NOT NULL,
    sent_proof text NOT NULL,
    UNIQUE (user_id, operation_key)
);
CREATE TABLE oc_mail_mcp_draft_copies (
    operation_key text PRIMARY KEY,
    target_account_id integer NOT NULL REFERENCES oc_mail_accounts(id) ON DELETE CASCADE,
    local_draft_id integer REFERENCES oc_mail_local_messages(id) ON DELETE SET NULL,
    user_id text NOT NULL,
    approval_digest text NOT NULL,
    state text NOT NULL,
    source_refs text NOT NULL,
    created_at bigint NOT NULL
);
CREATE TABLE oc_mail_mcp_erase_accounts (erasure_id text NOT NULL);
CREATE TABLE oc_mail_mcp_erasure (id text PRIMARY KEY, state text NOT NULL);
CREATE TABLE oc_mail_mcp_sieve (id text PRIMARY KEY, account_id integer);
CREATE TABLE oc_mail_mcp_snoozes (
    id text PRIMARY KEY,
    state text NOT NULL,
    active_source text,
    placement_phase text NOT NULL,
    wake_phase text NOT NULL
);

ALTER TABLE oc_appconfig OWNER TO oc_admin;
ALTER TABLE oc_migrations OWNER TO oc_admin;
ALTER TABLE oc_mail_accounts OWNER TO oc_admin;
ALTER TABLE oc_mail_mailboxes OWNER TO oc_admin;
ALTER TABLE oc_mail_messages OWNER TO oc_admin;
ALTER TABLE oc_mail_local_messages OWNER TO oc_admin;
ALTER TABLE oc_mail_attachments OWNER TO oc_admin;
ALTER TABLE oc_mail_recipients OWNER TO oc_admin;
ALTER TABLE oc_mail_message_tags OWNER TO oc_admin;
ALTER TABLE oc_mail_mcp_auth_contexts OWNER TO oc_admin;
ALTER TABLE oc_mail_mcp_auth_states OWNER TO oc_admin;
ALTER TABLE oc_mail_mcp_body_index OWNER TO oc_admin;
ALTER TABLE oc_mail_mcp_deliveries OWNER TO oc_admin;
ALTER TABLE oc_mail_mcp_draft_copies OWNER TO oc_admin;
ALTER TABLE oc_mail_mcp_erase_accounts OWNER TO oc_admin;
ALTER TABLE oc_mail_mcp_erasure OWNER TO oc_admin;
ALTER TABLE oc_mail_mcp_sieve OWNER TO oc_admin;
ALTER TABLE oc_mail_mcp_snoozes OWNER TO oc_admin;

INSERT INTO oc_appconfig (appid, configkey, configvalue) VALUES
    ('mail', 'installed_version', '5.12.5.13'),
    ('mail', 'enabled', 'no');
INSERT INTO oc_migrations (app, version) VALUES ('mail', '5125Date20261111130000');
INSERT INTO oc_mail_accounts VALUES (1, 7);
INSERT INTO oc_mail_mailboxes VALUES (1, 1, 4, 'synthetic-refresh-token');
INSERT INTO oc_mail_messages VALUES (1, '3', 'fixture');
INSERT INTO oc_mail_local_messages VALUES (1, 1, 1, NULL, 'protected fixture draft', true, 0, true);
INSERT INTO oc_mail_attachments VALUES (1, 1, 'fixture.txt');
INSERT INTO oc_mail_recipients VALUES (1, 1, 'fixture@example.test');
INSERT INTO oc_mail_message_tags VALUES
    (1, 1, 'exact-cache-link', 1),
    (2, NULL, 'legacy-wire-link', 1);
INSERT INTO oc_mail_mcp_auth_contexts VALUES ('context-1', 1);
INSERT INTO oc_mail_mcp_auth_states VALUES ('state-1', 'context-1');
INSERT INTO oc_mail_mcp_body_index VALUES (1, 1, 1, 1);
INSERT INTO oc_mail_mcp_deliveries VALUES
    ('delivery-1', 'mcp-it-alice', 'operation-1', 'revision-1', 'content-1', 'uncertain', 'not_started',
     'mail_send', 'UTC', 1, 1, 1, 0, 1, NULL, 'synthetic-artifact', '');
INSERT INTO oc_mail_mcp_draft_copies VALUES
    ('copy-1', 1, 1, 'mcp-it-alice', 'approval', 'created', '[]', 1);
