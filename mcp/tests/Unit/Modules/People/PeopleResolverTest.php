<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\People;
use OCA\Mcp\Modules\People\Search\PeopleResolver;
use OCP\Accounts\IAccount; use OCP\Accounts\IAccountManager; use OCP\Accounts\IAccountProperty;
use OCP\Collaboration\Collaborators\ISearch; use OCP\IUser; use OCP\IUserManager; use OCP\IUserSession;
use OCP\Profile\IProfileManager; use OCP\Share\IShare; use PHPUnit\Framework\TestCase; use Psr\Log\LoggerInterface;
final class PeopleResolverTest extends TestCase {
    private function match(string $uid,string $label,?string $subline=null):array { return ['label'=>$label,'subline'=>$subline,'value'=>['shareWith'=>$uid]]; }
    private function resolver(array $results,array $profiles,?array &$calls=null,array $hasMore=[]):PeopleResolver {
        $search=$this->createMock(ISearch::class);
        $search->method('search')->willReturnCallback(function(string $query,array $types,bool $lookup,int $limit,int $offset) use($results,&$calls,$hasMore):array { $calls[]=[$query,$types,$lookup,$limit,$offset]; return [$results[$query]??[],$hasMore[$query]??false]; });
        $actor=$this->createMock(IUser::class); $actor->method('getUID')->willReturn('actor');
        $session=$this->createMock(IUserSession::class); $session->method('getUser')->willReturn($actor);
        $users=$this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function(string $uid) use($profiles):?IUser { if($uid==='actor'){$u=$this->createMock(IUser::class);$u->method('getUID')->willReturn('actor');return $u;}if(!isset($profiles[$uid]))return null;$u=$this->createMock(IUser::class);$u->method('getUID')->willReturn($uid);$u->method('isEnabled')->willReturn($profiles[$uid]['enabled']??true);return $u; });
        $pm=$this->createMock(IProfileManager::class); $pm->method('isProfileEnabled')->willReturn(true);
        $pm->method('getProfileFields')->willReturnCallback(fn(IUser $target,?IUser $visitor):array=>$profiles[$target->getUID()]['fields']??[]);
        $pm->method('isProfileFieldVisible')->willReturnCallback(fn(string $field,IUser $target,?IUser $visitor):bool=>$profiles[$target->getUID()]['email_visible']??false);
        $accounts=$this->createMock(IAccountManager::class);
        $accounts->method('getAccount')->willReturnCallback(function(IUser $user) use($profiles):IAccount { $property=$this->createMock(IAccountProperty::class);$property->method('getValue')->willReturn($profiles[$user->getUID()]['email']??'');$account=$this->createMock(IAccount::class);$account->method('getProperty')->with(IAccountManager::PROPERTY_EMAIL)->willReturn($property);return $account; });
        return new PeopleResolver($search,$users,$session,$pm,$accounts,$this->createMock(LoggerInterface::class));
    }
    public function testResolvesBatchAndKeepsHomonymsAsSuggestionsWithoutAutoSelecting():void {
        $calls=[];$resolver=$this->resolver([
            'alex@example.test'=>['exact'=>['users'=>[$this->match('alex','Alex Example')]],'users'=>[]],
            'Alex'=>['exact'=>['users'=>[]],'users'=>[$this->match('alex','Alex Example'),$this->match('alex2','Alex Example')]],
            'hidden-id'=>[],
            'shared@example.test'=>['exact'=>['users'=>[$this->match('one','One'),$this->match('two','Two')]],'users'=>[]],
        ],[
            'alex'=>['fields'=>['displayname'=>'Alex Example','organisation'=>'Example Org'],'email_visible'=>true,'email'=>'alex@example.test'],
            'alex2'=>['fields'=>['displayname'=>'Alex Example'],'email_visible'=>false,'email'=>'private@example.test'],
            'one'=>['fields'=>['displayname'=>'One'],'email_visible'=>false], 'two'=>['fields'=>['displayname'=>'Two'],'email_visible'=>false],
        ],$calls);
        self::assertSame([
            ['query'=>'alex@example.test','status'=>'resolved','resolved_uid'=>'alex','truncated'=>false,'candidates'=>[['uid'=>'alex','displayName'=>'Alex Example','profile'=>['displayname'=>'Alex Example','organisation'=>'Example Org'],'email'=>'alex@example.test','match'=>'exact']]],
            ['query'=>'Alex','status'=>'ambiguous','resolved_uid'=>null,'truncated'=>false,'candidates'=>[['uid'=>'alex','displayName'=>'Alex Example','profile'=>['displayname'=>'Alex Example','organisation'=>'Example Org'],'email'=>'alex@example.test','match'=>'suggestion'],['uid'=>'alex2','displayName'=>'Alex Example','profile'=>['displayname'=>'Alex Example'],'match'=>'suggestion']]],
            ['query'=>'hidden-id','status'=>'no_match','resolved_uid'=>null,'truncated'=>false,'candidates'=>[]],
            ['query'=>'shared@example.test','status'=>'ambiguous','resolved_uid'=>null,'truncated'=>false,'candidates'=>[['uid'=>'one','displayName'=>'One','profile'=>['displayname'=>'One'],'match'=>'exact'],['uid'=>'two','displayName'=>'Two','profile'=>['displayname'=>'Two'],'match'=>'exact']]],
        ],$resolver->resolve(['queries'=>['alex@example.test','Alex','hidden-id','shared@example.test']],'actor'));
        self::assertSame([['alex@example.test',[IShare::TYPE_USER],false,PeopleResolver::MAX_CANDIDATES,0],['Alex',[IShare::TYPE_USER],false,PeopleResolver::MAX_CANDIDATES,0],['hidden-id',[IShare::TYPE_USER],false,PeopleResolver::MAX_CANDIDATES,0],['shared@example.test',[IShare::TYPE_USER],false,PeopleResolver::MAX_CANDIDATES,0]],$calls);
    }
    public function testDoesNotResolveWhenCoreReportsMoreCandidatesThanTheBound():void {
        $resolver=$this->resolver(['Sam'=>['exact'=>['users'=>[$this->match('sam','Sam')]]]],['sam'=>['fields'=>['displayname'=>'Sam']]],$calls,['Sam'=>true]);
        $result=$resolver->resolve(['queries'=>['Sam']],'actor')[0]; self::assertSame('ambiguous',$result['status']);self::assertNull($result['resolved_uid']);self::assertTrue($result['truncated']);
    }
    public function testSinglePartialSuggestionDoesNotResolveAutomatically():void {
        $resolver=$this->resolver(['alex'=>['exact'=>['users'=>[]],'users'=>[$this->match('alexander','Alexander')]]],
            ['alexander'=>['fields'=>['displayname'=>'Alexander']]]);
        $result=$resolver->resolve(['queries'=>['alex']],'actor')[0];
        self::assertSame('ambiguous',$result['status']);
        self::assertNull($result['resolved_uid']);
        self::assertSame('suggestion',$result['candidates'][0]['match']);
    }
    public function testMarksTruncatedWhenItsCandidateCapTrimsProviderRows():void {
        $matches=[];$profiles=[];
        for($i=0;$i<PeopleResolver::MAX_CANDIDATES+1;$i++) {
            $uid='candidate-'.$i;$matches[]=$this->match($uid,$uid);$profiles[$uid]=['fields'=>['displayname'=>$uid]];
        }
        $resolver=$this->resolver(['Alex'=>['exact'=>['users'=>[]],'users'=>$matches]],$profiles);
        $result=$resolver->resolve(['queries'=>['Alex']],'actor')[0];
        self::assertCount(PeopleResolver::MAX_CANDIDATES,$result['candidates']);
        self::assertTrue($result['truncated']);
    }
    public function testDoesNotSearchForAnotherActor():void {
        $search=$this->createMock(ISearch::class);$search->expects(self::never())->method('search');$session=$this->createMock(IUserSession::class);$actor=$this->createMock(IUser::class);$actor->method('getUID')->willReturn('different-actor');$session->method('getUser')->willReturn($actor);
        $resolver=new PeopleResolver($search,$this->createMock(IUserManager::class),$session,$this->createMock(IProfileManager::class),$this->createMock(IAccountManager::class),$this->createMock(LoggerInterface::class));$this->expectException(\InvalidArgumentException::class);$resolver->resolve(['queries'=>['Alex']],'actor');
    }
}
