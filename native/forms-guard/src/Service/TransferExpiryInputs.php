<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** No expiration hook replay: bind the exact inputs used by native activation and recheck them. */
final class TransferExpiryInputs {
    private const DEFAULTS=['shareapi_default_internal_expire_date'=>'no','shareapi_enforce_internal_expire_date'=>'no','shareapi_internal_expire_after_n_days'=>'7','internal_defaultExpDays'=>'7'];
    public static function hooks():void {
        $registered=(new \ReflectionProperty(\OC_Hook::class,'registered'))->getValue();
        if (($registered['\\OC\\Share']['verifyExpirationDate']??[])!==[]) {throw new \RuntimeException('Transfer expiration hook profile is unqualified');}
    }
    public static function policy(\OC\Share20\Manager $manager,ScopedTransferTimeZone $zone,?FinalReadConnection $readDb=null):array {
        self::hooks();$inputs=$zone->inputs();$fresh=new SharingConfig($readDb===null?new FreshCore($inputs['uid']):FinalReadCore::fresh($inputs['uid'],$readDb));
        $used=(new \ReflectionProperty(\OC\Share20\Manager::class,'config'))->getValue($manager);$values=[];
        foreach (self::DEFAULTS as $key=>$default) {
            $default=$key==='internal_defaultExpDays'?(string)(int)$values['shareapi_internal_expire_after_n_days']:$default;
            $value=$fresh->getAppValue('core',$key,$default);
            if (!is_string($value) || $value!==$used->getAppValue('core',$key,$default)) {throw new \RuntimeException('Transfer activation expiration policy changed');}
            $values[$key]=$value;
        }
        $tz=$zone->getTimeZone()->getName();
        if ($inputs!==$zone->inputs()) {throw new \RuntimeException('Transfer activation timezone changed');}
        return ['timezone_inputs'=>$inputs,'timezone'=>$tz,'config'=>$values];
    }
    public static function capture(\OC\Share20\Manager $manager,ScopedTransferTimeZone $zone):array {return ['policy'=>self::policy($manager,$zone),'at'=>time()];}
    public static function assertCurrent(array $captured,array $policy,?string $expiration,int $now):void {
        if (($captured['policy']??null)!==$policy || !is_int($captured['at']??null) || $now<$captured['at']) {throw new \RuntimeException('Transfer activation expiration inputs changed');}
        if ($expiration===null) {return;}
        // Same native past-date predicate after its timezone/end-of-day normalization.
        // Stable policy plus nondecreasing time cannot tighten native's maximum future-date ceiling.
        $zone=new \DateTimeZone($policy['timezone']);
        $expiry=(new \DateTimeImmutable($expiration))->setTimezone($zone)->setTime(23,59,59);
        $today=(new \DateTimeImmutable('@'.$now))->setTimezone($zone)->setTime(0,0,0);
        if ($today>=$expiry) {throw new \RuntimeException('Transfer activated expiration is in the past');}
    }
}
