<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Responses;
/** Typed MCP answers become native structures only against the currently admitted question schema. */
final class FormsAnswers {
    public static function native(array $entries,array $questions,?\Closure $files=null):array {
        $types=[];foreach($questions as $q)$types[$q['id']]=$q;
        $answers=[];
        foreach($entries as $entry) {
            $id=$entry['question_id'];
            if(isset($answers[$id])||!isset($types[$id]))throw new \InvalidArgumentException('Invalid or duplicate question reference.');
            $question=$types[$id];$mode=array_values(array_intersect(array_keys($entry),['values','option_ids','file_handles','rows']));
            if(count($mode)!==1)throw new \InvalidArgumentException('Use exactly one answer representation per question.');
            if($mode[0]==='file_handles') {
                if(($question['type']??'')!=='file'||$files===null)throw new \InvalidArgumentException('File answers require an admitted upload handle.');
                $values=array_map(static fn(string $handle):array=>$files($id,$handle),$entry['file_handles']);
            } elseif($mode[0]==='rows') {
                if(($question['type']??'')!=='grid')throw new \InvalidArgumentException('Rows require a grid question.');
                $values=self::grid($entry['rows'],$question['extraSettings']['questionType']??'');
            } elseif($mode[0]==='option_ids') {
                if(!in_array($question['type']??'',['dropdown','multiple','multiple_unique','ranking'],true))throw new \InvalidArgumentException('Option IDs require a choice or ranking question.');
                $values=array_map(static fn(int $id):string=>(string)$id,$entry['option_ids']);
                if(count(array_unique($values))!==count($values))throw new \InvalidArgumentException('Duplicate option reference.');
            } else {
                if(in_array($question['type']??'',['file','grid','ranking','multiple','multiple_unique','dropdown'],true))throw new \InvalidArgumentException('This question requires its typed answer representation.');
                $values=$entry['values'];
            }
            if(isset($entry['other'])) {
                if(empty($question['extraSettings']['allowOtherAnswer'])||!in_array($question['type']??'',['dropdown','multiple','multiple_unique'],true))throw new \InvalidArgumentException('This question does not accept another answer.');
                $values[]='system-other-answer:'.$entry['other'];
            }
            $answers[$id]=$values;
        }
        return $answers;
    }
    private static function grid(array $rows,string $type):array {
        $out=[];
        foreach($rows as $row) {
            $id=$row['row_id'];
            if(array_key_exists($id,$out))throw new \InvalidArgumentException('Duplicate grid row.');
            if($type==='number') {
                if(!isset($row['cells'])||isset($row['column_ids']))throw new \InvalidArgumentException('Numeric grids require cells.');
                $cells=[];
                foreach($row['cells'] as $cell) {
                    if(array_key_exists($cell['column_id'],$cells)||($cell['value']!==''&&!is_numeric($cell['value'])))throw new \InvalidArgumentException('Invalid grid cell.');
                    $cells[$cell['column_id']]=$cell['value'];
                }
                $out[$id]=$cells;
            } else {
                if(!isset($row['column_ids'])||isset($row['cells'])||!in_array($type,['radio','checkbox'],true))throw new \InvalidArgumentException('Choice grids require column IDs.');
                $columns=$row['column_ids'];
                if(count(array_unique($columns))!==count($columns)||($type==='radio'&&count($columns)!==1))throw new \InvalidArgumentException('Invalid grid choices.');
                $out[$id]=$type==='radio'?(string)$columns[0]:array_map(static fn(int $n):string=>(string)$n,$columns);
            }
        }
        return $out;
    }
}
