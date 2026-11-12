<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Presentation;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Operations\OperationJournal;
class ConfigurationSchema {
    public static function id():array{return ['type'=>'integer','minimum'=>1];}
    public static function intent(bool $create=false):array {
        $server=['type'=>'object','properties'=>['host'=>['type'=>'string','minLength'=>1,'maxLength'=>255], 'port'=>['type'=>'integer','minimum'=>1,'maximum'=>65535],
            'tls'=>['type'=>'string','enum'=>['ssl','tls']],'user'=>['type'=>'string','minLength'=>1,'maxLength'=>500]],'additionalProperties'=>false];
        $schema=['type'=>'object','properties'=>['name'=>['type'=>'string','maxLength'=>255],'email'=>['type'=>'string','minLength'=>1,'maxLength'=>500],
            'imap'=>$server,'smtp'=>$server,'auth_method'=>['type'=>'string','enum'=>['password','xoauth2']]],'additionalProperties'=>false,
            'description'=>'Only non-secret desired configuration. Enter passwords and OAuth consent in native Mail.'];
        if($create)$schema['required']=['email'];return $schema;
    }
    public static function object(array $properties,array $required,bool $write=false):array {
        if($write){$properties['operation_id']=OperationJournal::property();$properties['plan_state']=PlanState::property();}
        return ['type'=>'object','properties'=>$properties]+($required===[]?[]:['required'=>$required])+['additionalProperties'=>false];
    }
}
