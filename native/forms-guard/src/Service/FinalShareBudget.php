<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** One operation-wide budget shared with the final file projection. No permission decisions. */
final class FinalShareBudget {
    private int $entries=0;
    private int $bytes=0;
    public function __construct(private readonly ?\Closure $deadline=null, int $fileProjectionBytes=0) {
        if ($fileProjectionBytes<0 || $fileProjectionBytes>1048576) {throw new \RuntimeException('Final sharing byte budget exceeded');}
        $this->bytes=$fileProjectionBytes;
    }
    public static function encode(array $row):string {
        $check=static function(mixed $value,int $depth)use(&$check):void {
            if ($depth>64 || is_object($value) || is_resource($value) || is_float($value)) {throw new \RuntimeException('Final sharing projection must be closed data');}
            if (is_array($value)) {foreach ($value as $child) {$check($child,$depth+1);}}
        };
        $check($row,0);return json_encode($row,JSON_THROW_ON_ERROR);
    }
    public function record(array $row):void {
        ($this->deadline ?? static function():void {})();
        if (++$this->entries>10000) {throw new \RuntimeException('Final sharing cumulative row budget exceeded');}
        $this->bytes+=strlen(self::encode($row));
        if ($this->bytes>1048576) {throw new \RuntimeException('Final sharing byte budget exceeded');}
    }
    /** A single query cursor: batch 100 in RAM, never SQL OFFSET paging. Sentinel is never materialized. */
    public function rows(\Closure $fetch,\Closure $close,int $maximum):array {
        if ($maximum<1 || $maximum>1000) {throw new \LogicException('Invalid final sharing row limit');}
        $rows=[];$batch=[];$count=0;
        try {
            while (true) {
                ($this->deadline ?? static function():void {})();
                $row=$fetch();
                if ($row===false) {break;}
                if (++$count>$maximum) {throw new \RuntimeException('Final sharing row budget exceeded');}
                if (!is_array($row)) {throw new \RuntimeException('Final sharing row is malformed');}
                $this->record($row);$batch[]=$row;
                if (count($batch)===100) {array_push($rows,...$batch);$batch=[];}
            }
            array_push($rows,...$batch);
            return $rows;
        } finally {$close();}
    }
}
