<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Command;
use OCA\Mcp\Modules\Mail\Transmission\DeliveryWorker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
/** Private signed stdin protocol. The child also expires if the HTTP parent dies. */
class MailDelivery extends Command {
    public function __construct(private DeliveryWorker $worker){parent::__construct('mcp:mail-delivery');}
    protected function configure():void {$this->setDescription('Execute one signed bounded native delivery or Sent repair. Internal use.');}
    protected function execute(InputInterface $input,OutputInterface $output):int {
        $r=['status'=>'refused'];
        if(!function_exists('pcntl_alarm')||!function_exists('pcntl_async_signals')||!function_exists('pcntl_signal')){$output->writeln(json_encode($r));return self::SUCCESS;}
        pcntl_async_signals(true);pcntl_signal(SIGALRM,static function():void{exit(124);});pcntl_alarm(20);
        try {$raw=stream_get_contents(STDIN,\OCA\Mcp\Platform\Process\NativeMutationProcess::MAX_INPUT_BYTES+1);$ticket=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(strlen($raw)<=\OCA\Mcp\Platform\Process\NativeMutationProcess::MAX_INPUT_BYTES&&is_array($ticket))$r=$this->worker->run($ticket);}
        catch(\Throwable){$r=['status'=>'unknown'];}finally{pcntl_alarm(0);}
        $output->writeln(json_encode($r,JSON_THROW_ON_ERROR));return self::SUCCESS;
    }
}
