<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Platform\Process;
/** Private CLI isolation bounds the complete native handshake, including trickling socket reads. */
class NativeProbeProcess {
    public function run(array $command,string $cwd,array $payload,int $budgetMs=10000): array {
        if(!function_exists('proc_open')||!function_exists('proc_terminate'))return ['status'=>'unsupported','category'=>'process_unavailable'];
        $process=@proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd,null,['bypass_shell'=>true]);
        if(!is_resource($process))return ['status'=>'unsupported','category'=>'process_unavailable'];
        $out='';$timeout=false;$overflow=false;$deadline=hrtime(true)+max(1,min(10000,$budgetMs))*1000000;
        try {
            fwrite($pipes[0],json_encode($payload,JSON_THROW_ON_ERROR));fclose($pipes[0]);
            stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
            do {
                $out.=(string)stream_get_contents($pipes[1],4097-strlen($out));
                stream_get_contents($pipes[2],8192); // Native error streams never cross into logs or tool responses.
                if(strlen($out)>4096){$overflow=true;break;}
                $running=proc_get_status($process)['running'];
                if(!$running)break;
                if(hrtime(true)>=$deadline){$timeout=true;break;}
                usleep(10000);
            } while(true);
            if(!$timeout&&!$overflow)$out.=(string)stream_get_contents($pipes[1],4097-strlen($out));
            if($timeout||$overflow){proc_terminate($process);usleep(10000);if(proc_get_status($process)['running'])proc_terminate($process,9);}
        } finally {
            foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
            proc_close($process);
        }
        if($timeout)return ['status'=>'unknown','category'=>'timeout'];
        $data=json_decode($out,true);
        $statuses=['ok','failed','unsupported','unknown'];$categories=['authenticated','connection_or_authentication_failed','policy_refused','revision_changed','unsupported_transport','process_unavailable','missing_native_cli','unverified_result','timeout'];
        if($overflow||!is_array($data)||!in_array($data['status']??null,$statuses,true)||!in_array($data['category']??null,$categories,true))return ['status'=>'unknown','category'=>'unverified_result'];
        return ['status'=>$data['status'],'category'=>$data['category']];
    }
}
