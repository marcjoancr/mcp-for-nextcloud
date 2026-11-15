<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Closed Core policy input read immediately before direct native handler evaluation. */
final class FinalShareConfig extends \OC\AllConfig {
    public const KEYS=['shareapi_enabled','shareapi_exclude_groups','shareapi_exclude_groups_list','shareapi_only_share_with_group_members','shareapi_only_share_with_group_members_exclude_group_list','shareapi_allow_group_sharing'];
    private array $values=[];
    public function __construct(array $rows) {
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['configkey']??null) || !in_array($row['configkey'],self::KEYS,true) || !is_string($row['configvalue']??null) || array_key_exists($row['configkey'],$this->values)) {throw new \RuntimeException('Final sharing policy config is malformed');}
            $this->values[$row['configkey']]=$row['configvalue'];
        }
        $exclude=$this->getAppValue('core','shareapi_exclude_groups','no');
        if ($exclude && $exclude!=='no') {self::list($this->getAppValue('core','shareapi_exclude_groups_list',''),false);}
        if ($this->getAppValue('core','shareapi_only_share_with_group_members','no')==='yes') {self::list($this->getAppValue('core','shareapi_only_share_with_group_members_exclude_group_list',''),true);}
    }
    private static function list(string $raw,bool $emptyAllowed):void {
        if ($raw==='' && $emptyAllowed) {return;}
        try {$value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);} catch (\JsonException) {throw new \RuntimeException('Final sharing policy config requires repair');}
        if (!is_array($value) || !array_is_list($value)) {throw new \RuntimeException('Final sharing policy config requires repair');}
        $seen=[];
        foreach ($value as $gid) {
            if (!is_string($gid) || $gid==='' || str_contains($gid,"\0") || isset($seen[$gid])) {throw new \RuntimeException('Final sharing policy config requires repair');}
            $seen[$gid]=true;
        }
    }
    public function getAppValue($appName,$key,$default='') {
        if ($appName!=='core' || !in_array($key,self::KEYS,true)) {throw new \RuntimeException('Final sharing unexpected config read');}
        return $this->values[$key]??$default;
    }
    public function setAppValue($appName,$key,$value) {throw new \LogicException('Final sharing config writes are forbidden');}
}
