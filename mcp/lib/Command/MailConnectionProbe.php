<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Command;
use OCA\Mcp\Modules\Mail\Connection\NativeConnectionProbeWorker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
/** Private signed stdin protocol; credentials and server transcripts never enter command-line arguments. */
class MailConnectionProbe extends Command {
    public function __construct(private NativeConnectionProbeWorker $worker){parent::__construct('mcp:mail-connection-probe');}
    protected function configure(): void{$this->setDescription('Run a signed, bounded native Mail authentication probe. Internal use.');}
    protected function execute(InputInterface $input,OutputInterface $output): int {
        try{$raw=stream_get_contents(STDIN,4097);$ticket=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            $r=is_array($ticket)&&strlen($raw)<=4096?$this->worker->run($ticket):['status'=>'failed','category'=>'policy_refused'];
        }catch(\Throwable){$r=['status'=>'unknown','category'=>'unverified_result'];}
        $output->writeln(json_encode($r,JSON_THROW_ON_ERROR));return self::SUCCESS;
    }
}
