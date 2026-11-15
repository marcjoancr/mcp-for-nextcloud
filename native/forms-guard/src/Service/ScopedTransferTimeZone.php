<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Exact native timezone algorithm, with verified original-frame inputs and no ambient session. */
final class ScopedTransferTimeZone implements \OCP\IDateTimeZone {
    private function __construct(private readonly array $capability,private readonly ?FinalReadConnection $readDb=null) {}
    public static function capture(?FinalReadConnection $readDb=null):self {return new self(OriginContext::transferSharingAuthority(),$readDb);}
    /** No config values leave this process; metadata covers mutable system inputs across known waits. */
    public static function systemFingerprint():string {
        $fragments=glob(rtrim(\OC::$configDir,'/').'/*.config.php');if($fragments===false)throw new \RuntimeException('Transfer system configuration is unqualified');
        $files=[rtrim(\OC::$configDir,'/').'/config.php',...$fragments];sort($files,SORT_STRING);$rows=[];
        foreach ($files as $file) {if(!is_file($file)||is_link($file))throw new \RuntimeException('Transfer system configuration is unqualified');$rows[]=[basename($file),hash_file('sha256',$file)];}
        return hash('sha256',json_encode([$rows,getenv('NC_default_timezone')],JSON_THROW_ON_ERROR));
    }
    public function inputs():array {
        ($this->capability['fresh'])();
        $uid=$this->capability['uid'];$core=$this->readDb===null?new FreshCore($uid):FinalReadCore::fresh($uid,$this->readDb);$config=new SharingConfig($core);
        $preference=$config->getUserValue($uid,'core','timezone','');
        $default=$config->getSystemValueString('default_timezone','UTC');
        if (!is_string($preference)) {throw new \RuntimeException('Transfer timezone preference is unqualified');}
        ($this->capability['fresh'])();
        return ['uid'=>$uid,'preference'=>$preference,'default'=>$default,'system'=>self::systemFingerprint(),'php_timezone'=>date_default_timezone_get()];
    }
    private function native(array $inputs):\OC\DateTimeZone {
        ($this->capability['frame'])();
        return new \OC\DateTimeZone(new TransferTimeZoneConfig($inputs['uid'],$inputs['preference'],$inputs['default']),new TransferTimeZoneSession($inputs['uid'],$this->capability['frame']),new \Psr\Log\NullLogger());
    }
    public function getTimeZone(int|false $timestamp=false,?string $userId=null):\DateTimeZone {
        if ($userId!==null && $userId!==$this->capability['uid']) {throw new \RuntimeException('Transfer timezone subject changed');}
        $inputs=$this->inputs();$zone=$this->native($inputs)->getTimeZone($timestamp);
        if ($inputs!==$this->inputs()) {throw new \RuntimeException('Transfer timezone inputs changed');}
        return $zone;
    }
    public function getDefaultTimeZone():\DateTimeZone {
        $inputs=$this->inputs();$zone=$this->native($inputs)->getDefaultTimeZone();
        if ($inputs!==$this->inputs()) {throw new \RuntimeException('Transfer timezone inputs changed');}return $zone;
    }
}
