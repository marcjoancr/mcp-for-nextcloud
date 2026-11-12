<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Migration;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{SimpleMigrationStep,IOutput};
/** Preserve legacy header associations as unverified history; never guess their cached identity. */
final class Version5125Date20261109000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,\Closure $schemaClosure,array $options):?ISchemaWrapper {
        $s=$schemaClosure();$boxes=$s->getTable('mail_mailboxes');if(!$boxes->hasColumn('tag_cache_revision'))$boxes->addColumn('tag_cache_revision',Types::BIGINT,['notnull'=>true,'default'=>0]);$links=$s->getTable('mail_message_tags');$messages=$s->getTable('mail_messages');
        if(!$messages->hasColumn('tag_cache_generation'))$messages->addColumn('tag_cache_generation',Types::STRING,['length'=>10,'notnull'=>false,'default'=>null]);
        if(!$messages->hasColumn('tag_cache_profile'))$messages->addColumn('tag_cache_profile',Types::STRING,['length'=>64,'notnull'=>false,'default'=>null]);
        if(!$links->hasColumn('cached_message_id')){
            $links->addColumn('cached_message_id',Types::BIGINT,['notnull'=>false,'default'=>null]);
            $links->addUniqueIndex(['cached_message_id','tag_id'],'mail_tag_cached_unique');
            $links->addForeignKeyConstraint($messages->getName(),['cached_message_id'],['id'],['onDelete'=>'CASCADE'],'mail_tag_cached_message');
        }
        return $s;
    }
}
