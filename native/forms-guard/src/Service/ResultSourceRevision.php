<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\{IDBConnection,Server};
use OCP\AppFramework\Db\Entity;
/** Source identity only: never a capability or a replacement for the global approval fence. */
final class ResultSourceRevision {
    public static function epoch(int $formId):int {
        if($formId<1){throw new \RuntimeException('Invalid result source form');}
        $value=Server::get(IDBConnection::class)->executeQuery('SELECT revision FROM *PREFIX*forms_mcp_result_epoch WHERE form_id = ?',[$formId])->fetchOne();
        if($value===false){return 0;}
        $text=(string)$value;
        if(!preg_match('/^(0|[1-9][0-9]{0,18})$/D',$text)||strlen($text)>19||(strlen($text)===19&&strcmp($text,'9223372036854775805')>0)){
            throw new \RuntimeException('Forms result source epoch is corrupt or exhausted');
        }
        return (int)$text;
    }
    /** Called only by the existing serialized writer transaction. Missing epochs stay lazy on reads. */
    public static function touch(int $formId):void {Server::get(FormsRevisionFence::class)->touchResultSource($formId);}
    public static function forSubmission(int $submissionId):?int {
        $value=Server::get(IDBConnection::class)->executeQuery('SELECT form_id FROM *PREFIX*forms_v2_submissions WHERE id = ?',[$submissionId])->fetchOne();
        return $value===false?null:(int)$value;
    }
    private static function affected(string $kind,int $id):?int {
        if($kind==='Submission'){return self::forSubmission($id);}
        if($kind!=='Answer'){throw new \LogicException('Unsupported result mutation');}
        $value=Server::get(IDBConnection::class)->executeQuery('SELECT s.form_id FROM *PREFIX*forms_v2_submissions s INNER JOIN *PREFIX*forms_v2_answers a ON a.submission_id = s.id WHERE a.id = ?',[$id])->fetchOne();
        return $value===false?null:(int)$value;
    }
    /** Cover inherited native extension/import/UI writes, resolving old ownership before deletion or moves. */
    public static function mutate(string $kind,string $method,Entity $entity,callable $work):Entity {
        return FormsRevisionFence::run(function()use($kind,$method,$entity,$work):Entity {
            // Native QBMapper deliberately issues no SQL for unchanged entities.
            if($method==='update'&&$entity->getUpdatedFields()===[]){return $work();}
            $before=$method==='insert'?null:self::affected($kind,(int)$entity->getId());
            $result=$work();
            $after=$method==='delete'?null:self::affected($kind,(int)$result->getId());
            foreach(array_unique(array_filter([$before,$after],static fn($id)=>$id!==null))as$formId){self::touch($formId);}
            return $result;
        });
    }
    /** Hash only the bounded metadata actually rendered, never answer rows, counts or linked-file bookkeeping. */
    public static function token(int $formId,array $metadata):string {
        $projection=['form'=>$metadata['form'],'questions'=>$metadata['questions'],'timeZone'=>$metadata['timeZone']];
        $canonical=function(mixed $value)use(&$canonical):mixed {
            if(!is_array($value)){return $value;}
            if(!array_is_list($value)){ksort($value,SORT_STRING);}
            foreach($value as$key=>$child){$value[$key]=$canonical($child);}return $value;
        };
        // Native question/option order is explicit in each row; break equal-order SQL ties canonically by ID.
        usort($projection['questions'],static fn($a,$b)=>$a['id']<=>$b['id']);
        foreach($projection['questions']as&$question){usort($question['options'],static fn($a,$b)=>$a['id']<=>$b['id']);}unset($question);
        $json=json_encode($canonical($projection),JSON_THROW_ON_ERROR);
        if(strlen($json)>1048576){throw new \RuntimeException('Forms result source metadata exceeds projection budget');}
        return 'r1:'.self::epoch($formId).':'.hash('sha256',$json);
    }
}
