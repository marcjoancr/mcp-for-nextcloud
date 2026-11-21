<?php
/* UNAPPLIED R2 proposal. Native algorithms derived from pinned Nextcloud Circles 35.0.0.
 * SPDX-FileCopyrightText: Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
final class LocalTransport extends \OCA\Circles\Service\FederatedEventService {
/**
 * This confirmation is optional, method is just here to avoid going too far away on the process
 *
 * @param FederatedEvent $event
 * @param bool $local
 *
 * @throws InitiatorNotConfirmedException
 */
public function confirmInitiator(\OCA\Circles\Model\Federated\FederatedEvent $event, bool $local = false): void
{
    if ($event->canBypass(\OCA\Circles\Model\Federated\FederatedEvent::BYPASS_INITIATORCHECK)) {
        return;
    }
    $circle = $event->getCircle();
    if (!$circle->hasInitiator()) {
        throw new \OCA\Circles\Exceptions\InitiatorNotConfirmedException('Initiator does not exist');
    }
    if ($local) {
        if (!$this->configService->isLocalInstance($circle->getInitiator()->getInstance())) {
            throw new \OCA\Circles\Exceptions\InitiatorNotConfirmedException('Initiator is not from the instance at the origin of the request');
        }
    } else if ($circle->getInitiator()->getInstance() !== $event->getSender()) {
        throw new \OCA\Circles\Exceptions\InitiatorNotConfirmedException('Initiator must belong to the instance at the origin of the request');
    }
    if (!$event->canBypass(\OCA\Circles\Model\Federated\FederatedEvent::BYPASS_INITIATORMEMBERSHIP) && $circle->getInitiator()->getLevel() < \OCA\Circles\Model\Member::LEVEL_MEMBER) {
        throw new \OCA\Circles\Exceptions\InitiatorNotConfirmedException('Initiator must be a member of the Team');
    }
}
/**
 * @param FederatedEvent $event
 * @param bool $checkLocalOnly
 *
 * @return IFederatedItem
 * @throws FederatedEventException
 */
public function getFederatedItem(\OCA\Circles\Model\Federated\FederatedEvent $event, bool $checkLocalOnly = true): \OCA\Circles\IFederatedItem
{
    $class = $event->getClass(); $this->allowClass($class);
    try {
        $test = new \ReflectionClass($class);
    } catch (\ReflectionException $e) {
        throw new \OCA\Circles\Exceptions\FederatedEventException('ReflectionException with ' . $class . ': ' . $e->getMessage());
    }
    if (!in_array(\OCA\Circles\IFederatedItem::class, $test->getInterfaceNames())) {
        throw new \OCA\Circles\Exceptions\FederatedEventException($class . ' does not implements IFederatedItem');
    }
    $item = $this->item($class);
    if (!$item instanceof \OCA\Circles\IFederatedItem) {
        throw new \OCA\Circles\Exceptions\FederatedEventException($class . ' not an IFederatedItem');
    }
    if ($item instanceof \OCA\Circles\IFederatedItemHighSeverity) {
        $event->setSeverity(\OCA\Circles\Model\Federated\FederatedEvent::SEVERITY_HIGH);
    }
    $this->setFederatedEventBypass($event, $item);
    $this->confirmRequiredCondition($event, $item, $checkLocalOnly);
    $this->configureEvent($event, $item);
    //		$this->confirmSharedItem($event, $item);
    return $item;
}
/**
 * Some event might need to bypass some checks
 *
 * @param FederatedEvent $event
 * @param IFederatedItem $item
 */
private function setFederatedEventBypass(\OCA\Circles\Model\Federated\FederatedEvent $event, \OCA\Circles\IFederatedItem $item)
{
    if ($item instanceof \OCA\Circles\IFederatedItemLoopbackTest) {
        $event->bypass(\OCA\Circles\Model\Federated\FederatedEvent::BYPASS_CIRCLE);
        $event->bypass(\OCA\Circles\Model\Federated\FederatedEvent::BYPASS_INITIATORCHECK);
    }
    if ($item instanceof \OCA\Circles\IFederatedItemCircleCheckNotRequired) {
        $event->bypass(\OCA\Circles\Model\Federated\FederatedEvent::BYPASS_LOCALCIRCLECHECK);
    }
    if ($item instanceof \OCA\Circles\IFederatedItemMemberCheckNotRequired) {
        $event->bypass(\OCA\Circles\Model\Federated\FederatedEvent::BYPASS_LOCALMEMBERCHECK);
    }
    if ($item instanceof \OCA\Circles\IFederatedItemInitiatorCheckNotRequired) {
        $event->bypass(\OCA\Circles\Model\Federated\FederatedEvent::BYPASS_INITIATORCHECK);
    }
    if ($item instanceof \OCA\Circles\IFederatedItemInitiatorMembershipNotRequired) {
        $event->bypass(\OCA\Circles\Model\Federated\FederatedEvent::BYPASS_INITIATORMEMBERSHIP);
    }
}
/**
 * Some event might require additional check
 *
 * @param FederatedEvent $event
 * @param IFederatedItem $item
 * @param bool $checkLocalOnly
 *
 * @throws FederatedEventException
 */
private function confirmRequiredCondition(\OCA\Circles\Model\Federated\FederatedEvent $event, \OCA\Circles\IFederatedItem $item, bool $checkLocalOnly = true)
{
    if (!$event->canBypass(\OCA\Circles\Model\Federated\FederatedEvent::BYPASS_CIRCLE) && !$event->hasCircle()) {
        throw new \OCA\Circles\Exceptions\FederatedEventException('FederatedEvent has no Circle linked');
    }
    // TODO: enforce IFederatedItemMemberEmpty if no member
    if ($item instanceof \OCA\Circles\IFederatedItemMemberEmpty) {
        $event->setMember(null);
    } elseif ($item instanceof \OCA\Circles\IFederatedItemMemberRequired && !$event->hasMember()) {
        throw new \OCA\Circles\Exceptions\FederatedEventException('FederatedEvent has no Member linked');
    }
    if ($event->hasMember() && !$item instanceof \OCA\Circles\IFederatedItemMemberRequired && !$item instanceof \OCA\Circles\IFederatedItemMemberOptional) {
        throw new \OCA\Circles\Exceptions\FederatedEventException($item::class . ' does not implements IFederatedItemMemberOptional nor IFederatedItemMemberRequired');
    }
    if ($item instanceof \OCA\Circles\IFederatedItemMustBeInitializedLocally && $checkLocalOnly) {
        throw new \OCA\Circles\Exceptions\FederatedEventException('FederatedItem must be executed locally');
    }
}
/**
 * @param FederatedEvent $event
 * @param IFederatedItem $item
 */
private function configureEvent(\OCA\Circles\Model\Federated\FederatedEvent $event, \OCA\Circles\IFederatedItem $item)
{
    if ($item instanceof \OCA\Circles\IFederatedItemAsyncProcess && !$event->isForceSync()) {
        $event->setAsync(true);
    }
    if ($item instanceof \OCA\Circles\IFederatedItemLimitedToInstanceWithMembership) {
        $event->setLimitedToInstanceWithMember(true);
    }
    if ($item instanceof \OCA\Circles\IFederatedItemDataRequestOnly) {
        $event->setDataRequestOnly(true);
    }
}
/**
 * @param FederatedEvent $event
 *
 * @return RemoteInstance[]
 * @throws RequestBuilderException
 */
public function getInstances(\OCA\Circles\Model\Federated\FederatedEvent $event): array
{
    if (!$event->hasCircle()) {
        return [];
    }
    $circle = $event->getCircle();
    $broadcastAsFederated = $event->getData()->gBool('_broadcastAsFederated');
    $instances = $this->remoteRequest->getOutgoingRecipient($circle, $broadcastAsFederated);
    if ($event->isLimitedToInstanceWithMember()) {
        $knownInstances = $this->memberRequest->getMemberInstances($circle->getSingleId());
        $instances = array_filter(array_map(function (\OCA\Circles\Model\Federated\RemoteInstance $instance) use ($knownInstances) {
            if (!in_array($instance->getInstance(), $knownInstances)) {
                return null;
            }
            return $instance;
        }, $instances));
    }
    // Check that in case of event has Member, the instance of that member is in the list.
    if ($event->hasMember() && !$this->configService->isLocalInstance($event->getMember()->getInstance())) {
        $currentInstances = array_map(fn(\OCA\Circles\Model\Federated\RemoteInstance $instance): string => $instance->getInstance(), $instances);
        if (!in_array($event->getMember()->getInstance(), $currentInstances)) {
            try {
                $instances[] = $this->remoteRequest->getFromInstance($event->getMember()->getInstance());
            } catch (\OCA\Circles\Exceptions\RemoteNotFoundException) {
            }
        }
    }
    return $instances;
}
private array $queue=[];
private ?LocalEventState $phase=null;
private string $phaseKey='';
private ?array $envelope=null;
private ?array $proofBinding=null;
private bool $draining=false;
private bool $closed=false;
private \OCA\Circles\Service\ConfigService $configService;
private \OCA\Circles\Db\RemoteRequest $remoteRequest;
private \OCA\Circles\Db\MemberRequest $memberRequest;
private EventService $events;
/** $fresh creates another owned read lease; neither closure is serializable continuation authority. */
public function __construct(private Lease $lease,private \Closure $fresh,private \Closure $assertCurrentEffect,private ?string $intentKey,\OCP\EventDispatcher\IEventDispatcher $dispatcher,private ?\Closure $oneShotProof=null) {
    if(($intentKey===null)!==($oneShotProof!==null)){throw new \RuntimeException('Circle one-shot event authority is malformed');}
    if($intentKey!==null){$this->phaseKey=$intentKey;$this->phase=new LocalEventState($intentKey);}
    $this->configService=$lease->config();$this->remoteRequest=$lease->remotes();$this->memberRequest=$lease->members();
    $this->events=new EventService($this->lease,$dispatcher,new ActivityService());
    $this->assertOneShot();
}
private function assertOneShot(?array $eventBinding=null): void {
    if($this->closed){throw new \RuntimeException('Circle local transport is closed');}
    $this->lease->checkpoint();($this->assertCurrentEffect)();
    if($this->intentKey===null){
        if($this->oneShotProof===null){throw new \RuntimeException('Circle bulk event proof is unavailable');}
        $proof=($this->oneShotProof)($eventBinding??$this->proofBinding);
        if(!is_array($proof)||array_keys($proof)!==['operation','type','owner_id','share_owner','recipient','file_id']||!in_array($proof['operation'],['create','delete'],true)||$proof['type']!==7||$proof['owner_id']!==$this->lease->actorUid()||!is_string($proof['share_owner'])||$proof['share_owner']===''||!is_string($proof['recipient'])||$proof['recipient']===''||!is_int($proof['file_id'])||$proof['file_id']<1){throw new \RuntimeException('Circle bulk event proof changed');}
        if($this->envelope!==null&&$this->envelope!==$proof){throw new \RuntimeException('Circle bulk event authority changed');}$this->envelope=$proof;
        if($eventBinding!==null){if($this->proofBinding!==null&&$this->proofBinding!==$eventBinding){throw new \RuntimeException('Circle bulk event binding changed');}$this->proofBinding=$eventBinding;}
        return;
    }
    if(!preg_match('/^[a-f0-9]{64}$/D',$this->intentKey)){throw new \RuntimeException('Circle phase identity is malformed');}
    $r=$this->lease->db()->executeQuery('SELECT phase FROM *PREFIX*forms_mcp_share_intents WHERE intent_key = ? LIMIT 2',[$this->intentKey]);
    try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
    if(count($rows)!==1||$rows[0]['phase']!=='uncertain'){throw new \RuntimeException('Circle event requires claimed one-shot provider phase');}
    $envelope=\OCA\Forms\Mcp\Service\EffectEnvelope::get($this->intentKey);
    if(($envelope['type']??null)!==7||($envelope['owner_id']??null)!==$this->lease->actorUid()||($envelope['intent_key']??null)!==$this->intentKey){throw new \RuntimeException('Circle phase actor or type binding changed');}
    if($this->envelope!==null&&$this->envelope!==$envelope){throw new \RuntimeException('Circle one-shot envelope changed');}$this->envelope=$envelope;
}
/** Cheap phase checks only; full proofs still bracket verify callbacks and manage/result effects. */
private function assertAdmission(?array $binding=null): void {
    if($this->intentKey!==null){$this->assertOneShot($binding);return;}
    if($this->closed||$this->envelope===null){throw new \RuntimeException('Circle event admission is unavailable');}
    $this->lease->checkpoint();
    if($binding!==null&&($this->proofBinding===null||$this->proofBinding!==$binding)){throw new \RuntimeException('Circle event admission binding changed');}
}
private function allowClass(string $class): void {
    if(!in_array($class,[\OCA\Circles\FederatedItems\Files\FileShare::class,\OCA\Circles\FederatedItems\Files\FileUnshare::class],true)){throw new \RuntimeException('Circle event class is not qualified');}
}
private function item(string $class): \OCA\Circles\IFederatedItem {
    $this->allowClass($class);$this->lease->assertActive();
    if($this->closed){throw new \RuntimeException("Circle local transport is closed");} return match($class){\OCA\Circles\FederatedItems\Files\FileShare::class=>new FileShare($this->lease,new MountRequest(),$this->events,$this->lease->config()),\OCA\Circles\FederatedItems\Files\FileUnshare::class=>new FileUnshare($this->lease,new MountRequest(),$this->events,$this->lease->config())};
}
private function assertLocal(\OCA\Circles\Model\Federated\FederatedEvent $event): void {
    if(!$event->hasCircle()||!$event->getCircle()->isConfig(\OCA\Circles\Model\Circle::CFG_LOCAL)||!$this->configService->isLocalInstance($event->getCircle()->getInstance())){throw new \RuntimeException('Only a local native Circle is qualified');}
    // Native outgoing-recipient algorithm still executes. Any actual remote recipient refuses before effects.
    if($this->getInstances($event)!==[]){throw new \RuntimeException('Circle remote event recipients are not qualified');}
}
public function newEvent(\OCA\Circles\Model\Federated\FederatedEvent $event): array {
    try {
        if($event::class!==\OCA\Circles\Model\Federated\FederatedEvent::class){throw new \RuntimeException('Circle event declaration is not qualified');}
        $this->assertAdmission();
        $admissionBinding=$this->binding($event);
        $this->assertOneShot($admissionBinding);
        if($this->intentKey===null){$this->phaseKey=hash('sha256',"forms-circle-bulk-event-v1\0".json_encode([$this->envelope,$admissionBinding],JSON_THROW_ON_ERROR));$this->phase=new LocalEventState($this->phaseKey);}
        $phase=$this->phase??throw new \RuntimeException('Circle one-shot phase is unavailable');
        $entry=$phase->admit($this->phaseKey,$admissionBinding,function()use($event,$admissionBinding):array {
            $this->assertAdmission($admissionBinding);$this->allowClass($event->getClass());
            if($this->draining||$this->queue!==[]){throw new \RuntimeException('Nested Circle event queue is unavailable');}
            EventModes::incoming($event->isForceSync(),$event->isAsync(),$event->isDataRequestOnly(),$event->isLimitedToInstanceWithMember());
            $this->assertLocal($event);
            $event->setOrigin($this->configService->getLoopbackInstance())->resetData();
            // Native configureEvent supplies async=true. Only transport is replaced.
            $item=$this->getFederatedItem($event,false);$this->confirmInitiator($event,true);
            EventModes::configured($event->isForceSync(),$event->isAsync(),$event->isDataRequestOnly(),$event->isLimitedToInstanceWithMember());
            $event->setSender($event->getCircle()->getInstance());$binding=$this->binding($event);
            if($binding!==$admissionBinding){throw new \RuntimeException('Circle event changed during admission');}
            $this->assertOneShot();
            $item->verify($event);
            $this->assertOneShot();
            if($this->binding($event)!==$binding){throw new \RuntimeException('Circle verification changed the event binding');}
            EventModes::configured($event->isForceSync(),$event->isAsync(),$event->isDataRequestOnly(),$event->isLimitedToInstanceWithMember());
            $this->assertAdmission();
            return ['event'=>$event,'binding'=>$binding,'phase'=>$this->phase];
        });
        $this->queue[]=$entry;
        return $event->getOutcome();
    } catch(\Throwable $error){$this->close();throw $error;}
}
public function drain(): void {
    try {
        $this->assertAdmission();if($this->draining){throw new \LogicException('Circle event drain is reentrant');}$this->draining=true;
        foreach($this->queue as &$entry){
            $entry['phase']->claimManage();
            $fresh=($this->fresh)();
            if(!$fresh instanceof Lease||$fresh->actorUid()!==$this->lease->actorUid()){throw new \RuntimeException('Fresh Circle lease is unavailable');}
            try {
                $this->assertAdmission();$event=$entry['event'];
                if($this->binding($event)!==$entry['binding']){throw new \RuntimeException('Queued Circle event changed');}
                $event->setCircle($fresh->actorCircle($event->getCircle()->getSingleId()));
                $this->assertLocal($event);$this->confirmInitiator($event,true);
                if($this->binding($event)!==$entry['binding']){throw new \RuntimeException('Circle identity incarnation changed before event processing');}
                $item=$this->getFederatedItem($event,false);
                EventModes::configured($event->isForceSync(),$event->isAsync(),$event->isDataRequestOnly(),$event->isLimitedToInstanceWithMember());
                $this->assertOneShot();
                $item->manage($event);
                $this->assertOneShot();
                $entry['phase']->done();
                // Same map shape as native manageResults: instance => SimpleDataStore, only after DONE.
                $results=$entry['phase']->claimResult($this->configService->getLoopbackInstance(),$event->getResult());
                $item->result($event,$results);
                $this->assertOneShot();
                $entry['phase']->over();
            } finally {$fresh->close();}
        }
        unset($entry);
    } catch(\Throwable $error){$this->phase->poison();throw $error;} finally {$this->draining=false;$this->close();}
}
public function initBroadcast(\OCA\Circles\Model\Federated\FederatedEvent $event): bool {throw new \RuntimeException('Native Circle network transport is not qualified');}
public function manageResults(string $token): void {throw new \RuntimeException('Persisted Circle event replay is not qualified');}
public function __serialize(): array {throw new \LogicException('Circle transport cannot be persisted');}
private function __clone() {}

/**
 * @param Throwable $t
 * @param array $serializable
 */
public function t(\Throwable $t, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param Throwable $t
 * @param int $level
 * @param array $serializable
 */
public function throwable(\Throwable $t, int $level = 3, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param Exception $e
 * @param array $serializable
 */
public function e(\Exception $e, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param Exception $e
 * @param int|array $level
 * @param array $serializable
 */
public function exception(\Exception $e, $level = 3, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $message
 * @param bool $trace
 * @param array $serializable
 */
public function emergency(string $message, bool $trace = false, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $message
 * @param bool $trace
 * @param array $serializable
 */
public function alert(string $message, bool $trace = false, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $message
 * @param bool $trace
 * @param array $serializable
 */
public function warning(string $message, bool $trace = false, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $message
 * @param bool $trace
 * @param array $serializable
 */
public function notice(string $message, bool $trace = false, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $message
 * @param array $serializable
 */
public function debug(string $message, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param int $level
 * @param string $message
 * @param bool $trace
 * @param array $serializable
 */
public function log(int $level, string $message, bool $trace = false, array $serializable = []): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @return LoggerInterface
 */
public function logger(): \Psr\Log\LoggerInterface
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $key
 * @param string $value
 *
 * @param string $default
 *
 * @return string
 */
public function setup(string $key, string $value = '', string $default = ''): string
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $key
 * @param array $value
 * @param array $default
 *
 * @return array
 */
public function setupArray(string $key, array $value = [], array $default = []): array
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $key
 * @param int $value
 * @param int $default
 *
 * @return int
 */
public function setupInt(string $key, int $value = -999, int $default = 0): int
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $key
 *
 * @return string
 */
public function appConfig(string $key): string
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param int $size
 */
public function setMaxDownloadSize(int $size)
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param NCRequest $request
 *
 * @return array
 * @throws RequestNetworkException
 */
public function retrieveJson(\OCA\Circles\Tools\Model\NCRequest $request): array
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param NCRequest $request
 * @param bool $exceptionOnIssue
 *
 * @throws RequestNetworkException
 */
public function doRequest(\OCA\Circles\Tools\Model\NCRequest $request, bool $exceptionOnIssue = true): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @return IClientService
 */
public function clientService(): \OCP\Http\Client\IClientService
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * return Signatory by its Id from cache or from direct request.
 * Should be overwritten.
 *
 * @param string $keyId
 * @param bool $refresh
 *
 * @return NCSignatory
 * @throws SignatoryException
 */
public function retrieveSignatory(string $keyId, bool $refresh = false): \OCA\Circles\Tools\Model\NCSignatory
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param NCSignatory $signatory
 * @param string $keyId
 * @param array $params
 * @param NCRequest|null $request
 *
 * @throws SignatoryException
 */
public function downloadSignatory(\OCA\Circles\Tools\Model\NCSignatory $signatory, string $keyId = '', array $params = [], ?\OCA\Circles\Tools\Model\NCRequest $request = null): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param NCSignatory $signatory
 * @param array $json
 * @param string $keyId
 *
 * @throws SignatoryException
 */
public function updateSignatory(\OCA\Circles\Tools\Model\NCSignatory $signatory, array $json, string $keyId = ''): void
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $keyId
 *
 * @return string
 * @throws InvalidOriginException
 */
public function getKeyOrigin(string $keyId)
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param NCSignatory $signatory
 * @param string $digest
 * @param int $bits
 * @param int $type
 */
public function generateKeys(\OCA\Circles\Tools\Model\NCSignatory $signatory, string $digest = 'rsa', int $bits = 2048, int $type = OPENSSL_KEYTYPE_RSA)
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @throws SignatoryException
 */
public function signString(string $clear, \OCA\Circles\Tools\Model\NCSignatory $signatory): string
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @throws SignatureException
 */
public function verifyString(string $clear, string $signed, string $publicKey, string $algo = \OCA\Circles\Tools\Model\NCSignatory::SHA256)
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param string $body
 *
 * @return NCSignedRequest
 * @throws InvalidOriginException
 * @throws MalformedArrayException
 * @throws SignatoryException
 * @throws SignatureException
 */
public function incomingSignedRequest(string $body = ''): \OCA\Circles\Tools\Model\NCSignedRequest
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param NCRequest $request
 * @param NCSignatory $signatory
 *
 * @return NCSignedRequest
 * @throws SignatoryException
 */
public function signOutgoingRequest(\OCA\Circles\Tools\Model\NCRequest $request, \OCA\Circles\Tools\Model\NCSignatory $signatory): \OCA\Circles\Tools\Model\NCSignedRequest
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
/**
 * @param NCSignatory $signatory
 *
 * @return int
 */
public function getOpenSSLAlgo(\OCA\Circles\Tools\Model\NCSignatory $signatory): int
{
    throw new \RuntimeException("Circle inherited global capability is not qualified");
}
private function binding(\OCA\Circles\Model\Federated\FederatedEvent $event): array {
    $wrapper=$event->getParams()->gAll()['wrappedShare']??null;
    if(!$wrapper instanceof ShareWrapper){throw new \RuntimeException('Circle event requires an owned native share model');}
    $create=$this->intentKey!==null?($this->envelope['create']??null)===true:($this->envelope['operation']??null)==='create';
    $expected=$create?\OCA\Circles\FederatedItems\Files\FileShare::class:\OCA\Circles\FederatedItems\Files\FileUnshare::class;
    if($event->getClass()!==$expected||$event->getCircle()->getSingleId()!==($this->envelope['recipient']??null)||$wrapper->getShareType()!==7||$wrapper->getShareOwner()!==($this->envelope['share_owner']??$this->lease->actorUid())||$wrapper->getSharedWith()!==$event->getCircle()->getSingleId()||$wrapper->getFileSource()!==($this->envelope['file_id']??$wrapper->getFileSource())){throw new \RuntimeException('Circle event does not match claimed Files effect');}
    return [$event->getClass(),$event->getCircle()->getSingleId(),$event->getCircle()->getCreation(),$event->getCircle()->getSource(),$wrapper->getId(),$wrapper->getFileSource(),$wrapper->getPermissions(),$wrapper->getFileTarget()];
}
public function close(): void {$this->closed=true;$this->phase?->poison();$this->queue=[];}
public function __destruct() {$this->close();}
}
