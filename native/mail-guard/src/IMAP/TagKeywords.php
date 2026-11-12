<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\IMAP;
/** Matches the pinned native IMAPMessage keyword normalization. System-state keywords require their own workflow. */
final class TagKeywords {
    public static function atom(mixed $label):bool {return is_string($label)&&$label!==''&&strlen($label)<=64&&preg_match('/[\x00-\x20\x7f-\xff(){}%*"\\\\\]]/D',$label)===0;}
    public static function canonical(string $label):string {$lower=strtolower($label);foreach(['$label1','$label2','$label3','$label4','$label5']as$canonical)if(in_array($lower,self::forms($canonical),true))return $canonical;return $lower;}
    public static function valid(mixed $label):bool {
        return self::atom($label)&&$label===self::canonical($label)&&!in_array($label,['junk','nonjunk','notjunk','$junk','$notjunk','$phishing','$forwarded','$mdnsent'],true);
    }
    public static function forms(string $canonical):array {
        return match(strtolower($canonical)){
            '$label1'=>['$label1','$important','important','$labelimportant'],
            '$label2'=>['$label2','$labelwork'], '$label3'=>['$label3','$labelpersonal'],
            '$label4'=>['$label4','$labeltodo'], '$label5'=>['$label5','$labellater'],default=>[strtolower($canonical)]};
    }
}
