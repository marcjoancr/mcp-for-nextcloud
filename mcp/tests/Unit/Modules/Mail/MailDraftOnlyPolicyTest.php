<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
final class MailDraftOnlyPolicyTest extends TestCase {
    private function policy():object {
        require_once dirname(__DIR__,3).'/stubs/OCA/Mail/Exception/ClientException.php';
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Db/DraftOnlyStorage.php';
        return new class extends \stdClass {
            use \OCA\Mail\Db\DraftOnlyStorage;
            public function check(object $draft,array $row):void {$this->requireOrdinaryDraft($draft,$row);}
        };
    }
    private function draft(array $values=[]):object {
        return new class($values) {
            public function __construct(private array $values){}
            public function getSendAt():?int{return $this->values['send_at']??null;}
            public function getSmimeSign():bool{return $this->values['smime_sign']??false;}
            public function getSmimeEncrypt():bool{return $this->values['smime_encrypt']??false;}
            public function isPgpMime():bool{return $this->values['pgp_mime']??false;}
        };
    }
    public function testStaleObjectsCannotAdoptOrOverwriteStoredSecurityOrSchedules():void {
        $policy=$this->policy();
        foreach(['send_at'=>600,'smime_sign'=>true,'smime_encrypt'=>true,'pgp_mime'=>true] as $field=>$value){
            foreach(['requested','stored'] as $side){
                $refused=false;
                try{$policy->check($this->draft($side==='requested'?[$field=>$value]:[]),$side==='stored'?[$field=>$value]:[]);}
                catch(\OCA\Mail\Exception\ClientException){$refused=true;}
                self::assertTrue($refused,$side.':'.$field);
            }
        }
    }
    public function testAnOrdinaryDraftDoesNotRequireChangingItsSecurityFields():void {
        $draft=$this->draft();$this->policy()->check($draft,['send_at'=>null,'smime_sign'=>0,'smime_encrypt'=>'0','pgp_mime'=>false]);
        self::assertNull($draft->getSendAt());self::assertFalse($draft->getSmimeSign());
    }
}
