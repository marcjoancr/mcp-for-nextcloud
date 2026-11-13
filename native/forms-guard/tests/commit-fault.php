<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
/** Test-only real-connection decorator: underlying COMMIT succeeds, caller receives an exception. */
function formsCommitFault(\OCP\IDBConnection $db): \OCP\IDBConnection {
    $renderType=function(?\ReflectionType $type)use(&$renderType):string {
        if($type===null){return '';}
        if($type instanceof \ReflectionUnionType){return implode('|',array_map($renderType,$type->getTypes()));}
        if($type instanceof \ReflectionIntersectionType){return implode('&',array_map($renderType,$type->getTypes()));}
        $name=$type->getName();$code=$type->isBuiltin()?$name:'\\'.$name;
        return $type->allowsNull()&&!in_array($name,['mixed','null'],true)?'?'.$code:$code;
    };
    $methods='';
    foreach((new \ReflectionClass(\OCP\IDBConnection::class))->getMethods() as $method){
        $parameters=[];$arguments=[];
        foreach($method->getParameters() as $parameter){
            $code=$renderType($parameter->getType());$code.=($code===''?'':' ').($parameter->isPassedByReference()?'&':'').($parameter->isVariadic()?'...':'').'$'.$parameter->getName();
            if($parameter->isDefaultValueAvailable()){$code.=' = '.var_export($parameter->getDefaultValue(),true);}
            $parameters[]=$code;$arguments[]=($parameter->isVariadic()?'...':'').'$'.$parameter->getName();
        }
        $return=$renderType($method->getReturnType());$name=$method->getName();
        $body=$name==='commit'?'$this->inner->commit(); throw new \\RuntimeException("Injected lost database commit acknowledgement");':($return==='void'?'':'return ').'$this->inner->'.$name.'('.implode(',',$arguments).');';
        $methods.='public function '.$name.'('.implode(',',$parameters).')'.($return===''?'':': '.$return).'{'.$body.'}';
    }
    return eval('return new class($db) implements \\OCP\\IDBConnection { public function __construct(private \\OCP\\IDBConnection $inner){} '.$methods.'};');
}
