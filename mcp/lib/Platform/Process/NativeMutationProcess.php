<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Platform\Process;
/** Dedicated writer process: fixed acknowledgements only; durable proof lives in the operation table. */
class NativeMutationProcess {
    public const MAX_INPUT_BYTES=65536;
    public function run(array $command,string $cwd,array $payload,int $budgetMs=20000):array {
        $unknown=['status'=>'unknown'];$bytes=json_encode($payload,JSON_THROW_ON_ERROR);
        if(strlen($bytes)>self::MAX_INPUT_BYTES||!function_exists('proc_open')||!function_exists('proc_terminate'))return $unknown;
        $deadline=hrtime(true)+max(1,min(20000,$budgetMs))*1000000;
        $p=@proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd,null,['bypass_shell'=>true]);
        if(!is_resource($p))return $unknown;
        foreach($pipes as $pipe)stream_set_blocking($pipe,false);
        $out='';$offset=0;$failed=false;
        try {
            do {
                if(isset($pipes[0])){
                    $n=@fwrite($pipes[0],substr($bytes,$offset));if($n===false){$failed=true;break;}$offset+=$n;
                    if($offset===strlen($bytes)){fclose($pipes[0]);unset($pipes[0]);}
                }
                $out.=(string)stream_get_contents($pipes[1],4097-strlen($out));stream_get_contents($pipes[2],8192);
                if(strlen($out)>4096||hrtime(true)>=$deadline){$failed=true;break;}
                if(!proc_get_status($p)['running'])break;
                usleep(5000);
            }while(true);
            if(!$failed)$out.=(string)stream_get_contents($pipes[1],4097-strlen($out));
        }finally{
            if(proc_get_status($p)['running']){proc_terminate($p);usleep(10000);if(proc_get_status($p)['running'])proc_terminate($p,9);}
            foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($p);
        }
        if($failed||strlen($out)>4096)return $unknown;
        $data=json_decode($out,true);
        return is_array($data)&&in_array($data['status']??null,['completed','refused','unknown'],true)?['status'=>$data['status']]:$unknown;
    }
}
