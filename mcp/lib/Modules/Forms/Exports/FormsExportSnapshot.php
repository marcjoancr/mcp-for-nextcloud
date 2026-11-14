<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Exports;
/** Stable active-question columns; historical anonymous identities and unscoped files never enter the spool. */
final class FormsExportSnapshot {
    private array $columns=[];private \DateTimeZone $zone;private int $columnBytes=2;
    public function __construct(private array $metadata,private bool $identities,private bool $files) {
        try{$this->zone=new \DateTimeZone($metadata['timeZone']??'UTC');}catch(\Throwable){$this->zone=new \DateTimeZone('UTC');}
        $this->append(['id'=>'submission_id','title'=>'Submission ID','kind'=>'submission_id']);
        if($identities)foreach(['user_id'=>'User ID','user_display_name'=>'User display name'] as $id=>$title)$this->append(['id'=>$id,'title'=>$title,'kind'=>$id]);
        $this->append(['id'=>'timestamp','title'=>'Timestamp','kind'=>'timestamp']);
        foreach($metadata['questions']??[] as $q) {
            $base=['question_id'=>$q['id'],'type'=>$q['type'],'numeric'=>in_array($q['type'],['ranking','linearscale'],true)];
            if($q['type']==='grid') {
                $options=$q['options']??[];$labels=array_column($options,'text','id');
                $rows=array_filter($options,static fn(array $o):bool=>($o['optionType']??'')==='row');
                $cols=array_filter($options,static fn(array $o):bool=>($o['optionType']??'')==='column');
                $numeric=($q['extraSettings']['questionType']??'')==='number';
                $this->capacity($numeric?count($rows)*count($cols):count($rows));
                foreach($rows as $row) {
                    if(($q['extraSettings']['questionType']??'')==='number') {
                        foreach($cols as $col)$this->append(['id'=>"question-id-{$q['id']}-{$row['id']}-{$col['id']}",'title'=>$q['text'].' ('.$row['text'].' - '.$col['text'].')','kind'=>'grid_number','row'=>$row['id'],'column'=>$col['id'],'numeric'=>true]+$base);
                    } else $this->append(['id'=>"question-id-{$q['id']}-{$row['id']}",'title'=>$q['text'].' ('.$row['text'].')','kind'=>'grid_choice','row'=>$row['id'],'labels'=>$labels]+$base);
                }
            } elseif($q['type']==='ranking') {
                $this->capacity(count($q['options']??[]));
                foreach($q['options']??[] as $option)$this->append(['id'=>"question-id-{$q['id']}-{$option['id']}",'title'=>$q['text'].' ('.$option['text'].')','kind'=>'ranking','option_id'=>$option['id']]+$base);
            } else $this->append(['id'=>"question-id-{$q['id']}",'title'=>$q['text'],'kind'=>$q['type']==='file'?'file':'text']+$base);
        }

    }
    private function capacity(int $additional):void {
        if($additional>16384-count($this->columns))throw new \RuntimeException('Forms export exceeds spreadsheet column capacity.');
    }
    private function append(array $column):void {
        $this->capacity(1);$public=$column;unset($public['labels']);
        $bytes=strlen(json_encode($public,JSON_THROW_ON_ERROR))+1;
        if($bytes>1048576-$this->columnBytes)throw new \RuntimeException('Forms export exceeds its projection byte quota.');
        $this->columnBytes+=$bytes;$this->columns[]=$column;
    }
    /** Ordered fields shown during approval, without internal answer-label dictionaries. */
    public function approvalColumns():array {
        return array_map(static function(array $column):array {unset($column['labels']);return $column;},$this->columns);
    }
    public function columns():array {return $this->columns;}
    public function row(array $submission,array $visibleFiles=[]):array {
        $answers=[];foreach($submission['answers']??[] as $answer)$answers[$answer['questionId']][]=$answer;
        $anonymous=($this->metadata['form']['isAnonymous']??false)||str_starts_with($submission['userId']??'','anon-user-');
        $out=[];
        foreach($this->columns as $column) {
            $kind=$column['kind'];$values=$answers[$column['question_id']??0]??[];
            $json=isset($values[0]['text'])?json_decode($values[0]['text'],true):[];
            $value=match($kind) {
                'submission_id'=>(string)$submission['id'],
                'user_id'=>$anonymous?'':($submission['userId']??''),
                'user_display_name'=>$anonymous?'':($submission['userDisplayName']??''),
                'timestamp'=>(new \DateTimeImmutable('@'.$submission['timestamp']))->setTimezone($this->zone)->format('c'),
                'grid_number'=>isset($json[$column['row']][$column['column']])?(string)$json[$column['row']][$column['column']]:'',
                'grid_choice'=>$this->gridChoice($json[$column['row']]??null,$column['labels']),
                'ranking'=>$this->rank($json,$column['option_id']),
                'file'=>$this->fileCell($values,$visibleFiles),
                default=>implode('; ',array_column($values,'text')),
            };
            $out[]=$value;
        }
        return $out;
    }
    /** Per-response selections retain native boundaries even when option labels contain separators. */
    public function summaryCells(array $submission):array {
        $row=$this->row($submission);$answers=[];
        foreach($submission['answers']??[] as $answer)$answers[$answer['questionId']][]=$answer;
        foreach($this->columns as $index=>$column) {
            $items=$answers[$column['question_id']??0]??[];
            if($column['kind']==='file')$row[$index]=[(string)count($items)];
            elseif(in_array($column['type']??'',['multiple','multiple_unique','dropdown'],true))$row[$index]=array_values(array_filter(array_column($items,'text'),static fn(string $text):bool=>$text!==''));
            elseif($column['kind']==='grid_choice') {
                $json=isset($items[0]['text'])?json_decode($items[0]['text'],true):[];
                $ids=$json[$column['row']]??[];
                $ids=is_array($ids)?$ids:[$ids];
                $row[$index]=array_map(static fn(mixed $id):string=>$column['labels'][$id]??'', $ids);
            } else $row[$index]=$row[$index]===''?[]:[$row[$index]];
        }
        return $row;
    }
    private function gridChoice(mixed $ids,array $labels):string {
        if($ids===null)return '';
        return implode('; ',array_map(static fn(mixed $id):string=>$labels[$id]??'',is_array($ids)?$ids:[$ids]));
    }
    private function rank(mixed $ids,int $option):string {
        if(!is_array($ids))return '';$position=array_search($option,$ids);
        return $position===false?'':(string)($position+1);
    }
    private function fileCell(array $answers,array $visible):array|string {
        $text=$this->fileLabels($answers,$visible);
        if($text==='')return '';
        foreach($answers as $answer)if(isset($visible[$answer['fileId']??0]['url']))return ['text'=>$text,'url'=>$visible[$answer['fileId']]['url']];
        return $text;
    }
    private function fileLabels(array $answers,array $visible):string {
        if(!$this->files)return '';$out=[];
        foreach($answers as $a)if(isset($visible[$a['fileId']??0])) {
            $f=$visible[$a['fileId']];$out[]=$f['name'].(isset($f['url'])?' ('.$f['url'].')':'');
        }
        return implode("; \n",$out);
    }
}
