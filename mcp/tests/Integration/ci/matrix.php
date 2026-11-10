<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

$arguments = array_slice($argv, 1);
$mail = end($arguments) === '--mail';
if ($mail) { array_pop($arguments); }
$runtimePhase=getenv('MCP_IT_MAIL_RUNTIME_PHASE')?:'';
$mailGuard=getenv('MCP_IT_MAIL_GUARD')?:'0';
$extended=$mail&&in_array($arguments[0]??null,['33','34'],true);
if(($runtimePhase!==''&&(!in_array($runtimePhase,['candidate','enabled'],true)||!$extended||$mailGuard!=='1'))
    ||($extended&&(!in_array($mailGuard,['0','1'],true)||($mailGuard==='1'&&$runtimePhase==='')))){
    fwrite(STDERR,"Guarded extended Mail cells require an explicit candidate/enabled qualification phase.\n");exit(2);
}
$qualificationKit=getenv('MCP_IT_CANDIDATE_KIT');
if($runtimePhase!==''&&is_string($qualificationKit)&&$qualificationKit!==''){
    fwrite(STDERR,"Runtime qualification phases require selected checkout source; external kits are unsupported.\n");exit(2);
}
$mailPins = json_decode(file_get_contents(__DIR__ . '/mail-pins.json'), true, 512, JSON_THROW_ON_ERROR);
$formsPins = json_decode(file_get_contents(__DIR__ . '/forms-pins.json'), true, 512, JSON_THROW_ON_ERROR);
$manifest = json_decode(file_get_contents(__DIR__ . '/matrix.json'), true, 512, JSON_THROW_ON_ERROR);
$plans = [];
foreach ($manifest['cells'] as $cell) {
    $modes = ['fresh', 'upgrade'];
    if ($cell['nc'] === '35' && $cell['db'] === 'mariadb') {
        $modes = [...$modes, 'upgrade-local', 'upgrade-upstream', 'upgrade-upstream-111', 'upgrade-upstream-112', 'upgrade-upstream-120'];
        // This host comparison requires the published archive and a verified candidate kit.
        if ($arguments === ['35', 'mariadb', 'upgrade-upstream-121'] && getenv('MCP_IT_CANDIDATE_KIT')) {
            $modes[] = 'upgrade-upstream-121';
        }
    }
    if ($cell['nc'] === '35' && $cell['db'] === 'mariadb' && $arguments === ['35', 'mariadb', 'upgrade-permissions']) {
        $modes[] = 'upgrade-permissions';
    }
    foreach ($modes as $mode) {
        $plans[] = $cell + ['mode' => $mode, 'baseline_commit' => $manifest['baselines'][$mode] ?? null,
            'publishes_ports' => false, 'disposable' => true, 'mail' => $mail ? $mailPins : null,
            'forms' => $mail ? $formsPins : null, 'mail_runtime_phase'=>$mail?$runtimePhase:''];
    }
}
if (array_slice($argv, 1) === ['--matrix']) {
    echo json_encode(['include' => $plans], JSON_THROW_ON_ERROR), PHP_EOL;
    exit(0);
}
foreach ($plans as $plan) {
    if ($arguments === [$plan['nc'], $plan['db'], $plan['mode']] && (!$mail || ($plan['db'] === 'mariadb' && (($plan['nc'] === '35' && in_array($plan['mode'], ['fresh', 'upgrade-local'], true)) || (in_array($plan['nc'], ['33','34'], true) && $plan['mode'] === 'fresh'))))) {
        if($runtimePhase!==''){
            require_once dirname(__DIR__,3).'/lib/Modules/Mail/Integration/MailRuntimePairs.php';
            require_once dirname(__DIR__,3).'/lib/Modules/Mail/Integration/NativeGuardManifest.php';
            if(preg_match('/^nextcloud:([0-9]+\.[0-9]+\.[0-9]+)-apache@sha256:[a-f0-9]{64}$/D',$plan['nc_image'],$runtimeCore)!==1){
                fwrite(STDERR,"Unsupported pinned core identity for Mail qualification.\n");exit(2);
            }
            $admitted=\OCA\Mcp\Modules\Mail\Integration\MailRuntimePairs::matches($runtimeCore[1],\OCA\Mcp\Modules\Mail\Integration\NativeGuardManifest::DATA['build_version']);
            if($runtimePhase==='candidate'&&$admitted){fwrite(STDERR,"Candidate phase requires pre-admission source with this exact native pair closed.\n");exit(2);}
            if($runtimePhase==='enabled'&&!$admitted){fwrite(STDERR,"Enabled phase requires source admitting this exact native pair.\n");exit(2);}
        }
        echo json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
        exit(0);
    }
}
fwrite(STDERR, "Expected a supported Nextcloud/database/mode cell; use matrix.php --matrix to list cells.\n");
exit(2);
