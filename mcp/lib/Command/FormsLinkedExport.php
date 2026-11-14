<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Command;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedExportWorker;
use OCA\Mcp\Platform\Process\NativeMutationProcess;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
/** Internal stdin-only protocol; durable native leases and outcomes survive an unacknowledged child exit. */
final class FormsLinkedExport extends Command {
    public function __construct(private FormsLinkedExportWorker $worker) {parent::__construct('mcp:forms-linked-export');}
    protected function configure():void {$this->setDescription('Execute one isolated bounded Forms export step. Internal use.');}
    protected function execute(InputInterface $input,OutputInterface $output):int {
        $result=['status'=>'refused'];
        if(PHP_SAPI!=='cli'||!function_exists('pcntl_alarm')||!function_exists('pcntl_async_signals')||!function_exists('pcntl_signal')) {
            $output->writeln(json_encode($result));return self::SUCCESS;
        }
        pcntl_async_signals(true);pcntl_signal(SIGALRM,static function():void {exit(124);});pcntl_alarm(20);
        try {
            $raw=stream_get_contents(STDIN,NativeMutationProcess::MAX_INPUT_BYTES+1);
            if(is_string($raw)&&strlen($raw)<=NativeMutationProcess::MAX_INPUT_BYTES) {
                $ticket=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
                if(is_array($ticket))$result=$this->worker->run($ticket);
            }
        } catch(\Throwable) {$result=['status'=>'unknown'];}finally{pcntl_alarm(0);}
        $output->writeln(json_encode($result,JSON_THROW_ON_ERROR));return self::SUCCESS;
    }
}
