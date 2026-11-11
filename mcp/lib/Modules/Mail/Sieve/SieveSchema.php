<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Sieve;
use OCA\Mcp\Modules\Mail\Presentation\ConfigurationSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
final class SieveSchema {
    private static function object(array $p,array $r):array{return ['type'=>'object','properties'=>$p,'required'=>$r,'additionalProperties'=>false];}
    public static function definition(string $operation,bool $write):ToolDefinition {
        $p=['account_id'=>ConfigurationSchema::id()];$required=['account_id'];
        if(in_array($operation,['write_filters','transmit_filters'],true)){
            $text=['type'=>'string','maxLength'=>256];$test=self::object(['field'=>['type'=>'string','enum'=>['subject','to','from']],'operator'=>['type'=>'string','enum'=>['is','contains','matches']],'values'=>['type'=>'array','items'=>$text,'minItems'=>1,'maxItems'=>10]],['field','operator','values']);
            $types=['fileinto','addflag','keep','stop'];if($operation==='transmit_filters')$types[]='redirect';$actionProperties=['type'=>['type'=>'string','enum'=>$types],'mailbox'=>['type'=>'string','minLength'=>1,'maxLength'=>255],'flag'=>['type'=>'string','minLength'=>1,'maxLength'=>63]];if($operation==='transmit_filters')$actionProperties['recipient']=['type'=>'string','minLength'=>1,'maxLength'=>254];$action=self::object($actionProperties,['type']);

            $filter=self::object(['name'=>['type'=>'string','maxLength'=>255],'enable'=>['type'=>'boolean'],'priority'=>['type'=>'integer','minimum'=>0,'maximum'=>100000],'operator'=>['type'=>'string','enum'=>['allof','anyof']],'tests'=>['type'=>'array','items'=>$test,'minItems'=>1,'maxItems'=>10],'actions'=>['type'=>'array','items'=>$action,'minItems'=>1,'maxItems'=>10]],['name','enable','priority','operator','tests','actions']);$p['filters']=['type'=>'array','items'=>$filter,'maxItems'=>50];$required[]='filters';
        }elseif(in_array($operation,['write_absence','enable_absence'],true)){
            $transmit=$operation==='enable_absence';$a=['version'=>['type'=>'integer','enum'=>[1]],'enabled'=>$transmit?['type'=>'boolean']:['type'=>'boolean','enum'=>[false]],'subject'=>['type'=>'string','maxLength'=>255],'message'=>['type'=>'string','maxLength'=>20000],'start'=>['type'=>'string','format'=>'date-time'],'end'=>['type'=>'string','format'=>'date-time']];$p['absence']=self::object($a,['version','enabled','subject','message']);$p['follow_system']=$transmit?['type'=>'boolean']:['type'=>'boolean','enum'=>[false]];$required=array_merge($required,['absence','follow_system']);
        }elseif($operation==='read_sieve_status'){$p=['sieve_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$']];$required=['sieve_id'];}
        return new ToolDefinition('mail_'.$operation,'mail',$operation,match($operation){
            'read_filters'=>'Read bounded recognized native filters. Unknown executable sections are opaque; no raw script is exposed.',
            'read_absence'=>'Read bounded recognized absence configuration and system-follow state, without raw script or credentials.',
            'write_filters'=>'Preview and confirm safe future-delivery filters. Refuses any final emitting or opaque program. Native Sieve setup and a whole-account grant are required.',
            'transmit_filters'=>'Preview and confirm filters including redirect-copy. Requires this separate transmission permission and the operator transmission gate.',
            'write_absence'=>'Preview and confirm disabled absence configuration with system-follow off. Refuses any final emitting or opaque program.',
            'enable_absence'=>'Preview and confirm bounded vacation or system-follow enablement. Requires this separate transmission permission and the operator transmission gate.',
            'disable_absence'=>'Preview and confirm disabling a recognized absence block and system-follow. Refuses other emitting or opaque executable sections.',
            default=>'Observe an original Sieve attempt under its original actor, connection and grants. Never reinstalls, reactivates, rolls back or deletes scripts; no external CAS is claimed.',
        },ConfigurationSchema::object($p,$required,$write),app:'mail');
    }
}
