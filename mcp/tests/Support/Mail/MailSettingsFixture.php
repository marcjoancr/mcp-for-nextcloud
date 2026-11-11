<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Support\Mail;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Mail\Integration\{MailServices,MailGateway,MailSettingsGateway};
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\IAppConfig;
require_once __DIR__.'/../../stubs/OCA/Mail/Exception/ClientException.php';
trait MailSettingsFixture {
    public function fixture(bool $creationAllowed=true): array {
        $entity=new SettingsNativeEntity(['id'=>4,'userId'=>'alice','name'=>'Work','email'=>'alice@example.test',
            'editorMode'=>'html','showSubscribedOnly'=>false,'signatureAboveQuote'=>true,'signature'=>'<p>Alice</p>',
            'inboundHost'=>'imap.example.test','inboundPort'=>993,'inboundSslMode'=>'ssl','inboundUser'=>'alice',
            'outboundHost'=>'smtp.example.test','outboundPort'=>587,'outboundSslMode'=>'tls','outboundUser'=>'alice',
            'authMethod'=>'password','provisioningId'=>null,'inboundPassword'=>'SECRET-IMAP','outboundPassword'=>'SECRET-SMTP',
            'oauthRefreshToken'=>'SECRET-REFRESH','smimeSign'=>true]);
        $account=new SettingsNativeAccount($entity);
        $service=new class($account) {
            public int $writes=0;
            public array $accounts=[];
            public function __construct(object $a){$this->accounts[4]=$a;}
            public function find(string $uid,int $id): object {
                if(!isset($this->accounts[$id])||$this->accounts[$id]->getUserId()!==$uid)throw new \OCA\Mail\Exception\ClientException('Private account');
                return $this->accounts[$id];
            }
            public function findByUserId(string $uid): array{return array_values(array_filter($this->accounts,static fn(object $a):bool=>$a->getUserId()===$uid));}
            public function delete(string $uid,int $id):void{$this->find($uid,$id);$this->writes++;unset($this->accounts[$id]);}
            public function update(object $e): object{$this->writes++;return $e;}
            public function updateSignature(int $id,string $uid,?string $s): void {$this->writes++;$this->find($uid,$id)->getMailAccount()->setSignature($s);}
        };
        $alias=new SettingsNativeEntity(['id'=>3,'accountId'=>4,'signature'=>'Alias signature']);
        $aliases=new class($alias){
            public function __construct(private object $a){}
            public function find(int $id,string $uid): object{if($id!==3||$uid!=='alice')throw new \OCA\Mail\Exception\ClientException('Private alias');return $this->a;}
            public function findAll(int $id,string $uid):array{return $id===4&&$uid==='alice'?[$this->a]:[];}
            public function updateSignature(string $uid,int $id,?string $s): object{$a=$this->find($id,$uid);$a->setSignature($s);return $a;}
        };
        $services=$this->createMock(MailServices::class);$services->method('accountService')->willReturn($service);$services->method('aliasesService')->willReturn($aliases);
        $policy=$this->createMock(IAppConfig::class);$policy->method('getValueBool')->willReturn($creationAllowed);
        $states=new PlanState((new InMemoryConfig())->mock($this));
        $gateway=new MailSettingsGateway(new MailGateway($services, access: new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard(new \OCA\Mcp\Tests\Support\Dav\ScopeStub())),$services,$states,$policy);
        return [$gateway,$entity,$service,$alias,$states,$services];
    }
}
final class SettingsNativeEntity implements \JsonSerializable {
    public function __construct(private array $values){}
    public function __call(string $m,array $a): mixed {$key=lcfirst(preg_replace('/^(get|set|is)/','',$m));if(str_starts_with($m,'set')){$this->values[$key]=$a[0];return null;}return $this->values[$key]??null;}
    public function canAuthenticateImap(): bool{return $this->values['authPresent']??true;}
    public function jsonSerialize(): mixed{throw new \LogicException('Never serialize a native account.');}
}

final class SettingsNativeAccount {
    public function __construct(private SettingsNativeEntity $e){}
    public function getMailAccount(): object{return $this->e;}
    public function getId(): int{return $this->e->getId();}
    public function getUserId(): string{return $this->e->getUserId();}
    public function getName(): string{return $this->e->getName();}
    public function getEmail(): string{return $this->e->getEmail();}
}
