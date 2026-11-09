<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCA\Mcp\Modules\Mail\Presentation\MailRows;
/** Native local metadata only. Does not use MailManager's name-based create-or-return path. */
class MailTagGateway {
    public function __construct(private MailServices $native,private MailCapabilities $capabilities,private MailTagBoundary $boundary,private \OCP\IDBConnection $db,private \OCP\L10N\IFactory $l10n){}
    private function mapper(string $uid):object{return $this->native->newTagMapper($uid,$this->db,$this->l10n->get('mail'));}
    public function prepare(string $uid,array $args):array {
        $this->capabilities->requireSupported($uid,'create_tag');$this->boundary->requireStorage();$name=$args['name']??null;$color=$args['color']??null;
        if(!is_string($name)||$name===''||strlen($name)>128||trim($name)!==$name||MailRows::text($name)!==$name||preg_match('/[\x00-\x1f\x7f]/u',$name)||!is_string($color)||preg_match('/^#[0-9a-fA-F]{6}$/D',$color)!==1)throw new \InvalidArgumentException('Choose a literal tag name and a #RRGGBB color.');
        $full=mb_convert_encoding(str_replace(' ','_',$name),'UTF7-IMAP','UTF-8');$label=$this->native->tagCodec($uid)->create($name);
        if(!\OCA\Mcp\Modules\Mail\Mutations\TagMutationRequest::keyword($label)||strlen($full)>63||!is_string($label)||strlen($label)>64||!str_starts_with($label,'$')||strlen($label)<2||strpbrk($label,"(){}%*\"\\] \t\r\n")!==false||preg_match('/[^\x21-\x7e]/',$label)||preg_match('/^\$label[1-5]$/iD',$label))throw new \InvalidArgumentException('This tag cannot be represented as a complete non-reserved native keyword.');
        $rows=$this->mapper($uid)->getAllTagsForUser($uid);if(count($rows)>=1000)throw new BeforeEffectFailure('The owned tag inventory is at its supported limit.');$state=[];
        foreach($rows as$t){if($t->getUserId()!==$uid)continue;if(strcasecmp($t->getImapLabel(),$label)===0)throw new BeforeEffectFailure('A native tag with this canonical label already exists. Choose an existing tag or a distinct name; creation is not deduplicated by name.');$state[]=[(int)$t->getId(),$t->getImapLabel(),$t->getDisplayName(),$t->getColor()];}
        usort($state,static fn(array $a,array $b):int=>$a[0]<=>$b[0]);return ['name'=>$name,'color'=>strtolower($color),'imap_label'=>$label,'inventory_revision'=>hash('sha256',json_encode($state,JSON_THROW_ON_ERROR))];
    }
    public function insert(string $uid,array $prepared):int {
        $tag=$this->native->newTag($uid);$tag->setUserId($uid);$tag->setDisplayName($prepared['name']);$tag->setColor($prepared['color']);$tag->setImapLabel($prepared['imap_label']);$tag->setIsDefaultTag(false);
        $id=(int)$this->mapper($uid)->insert($tag)->getId();if($id<1)throw new \RuntimeException('Native tag identity unavailable.');return $id;
    }
    public function read(string $uid,int $id):array {
        $t=$this->mapper($uid)->getTagForUser($id,$uid);if((int)$t->getId()!==$id||$t->getUserId()!==$uid||$t->getIsDefaultTag()!==false)throw new \RuntimeException('Native tag readback unavailable.');
        return ['tag_id'=>$id,'name'=>$t->getDisplayName(),'color'=>$t->getColor(),'imap_label'=>$t->getImapLabel()];
    }
}
