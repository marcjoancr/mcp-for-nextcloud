<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Comments;
use OCP\Comments\IComment;
final class CommentSnapshot {
    public static function freeze(IComment $comment): array {
        return ['id'=>$comment->getId(),'parent_id'=>$comment->getParentId(),'topmost_parent_id'=>$comment->getTopmostParentId(),
            'children_count'=>$comment->getChildrenCount(),'message'=>$comment->getMessage(),'verb'=>$comment->getVerb(),
            'actor_type'=>$comment->getActorType(),'actor_id'=>$comment->getActorId(),'object_type'=>$comment->getObjectType(),'object_id'=>$comment->getObjectId(),
            'reference_id'=>$comment->getReferenceId(),'meta_data'=>$comment->getMetaData(),'reactions'=>$comment->getReactions(),
            'creation_timestamp'=>$comment->getCreationDateTime()->format('Y-m-d H:i:s.uP'),
            'latest_child_timestamp'=>$comment->getLatestChildDateTime()?->format('Y-m-d H:i:s.uP'),'expire_date'=>$comment->getExpireDate()?->format('Y-m-d H:i:s.uP')];
    }
    public static function thaw(array $data): IComment {
        foreach (['creation_timestamp','latest_child_timestamp','expire_date'] as $field) {$data[$field]=$data[$field]===null?null:new \DateTime($data[$field]);}
        return new \OC\Comments\Comment($data);
    }
}
