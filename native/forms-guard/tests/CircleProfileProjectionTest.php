<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use PhpParser\{Node,NodeFinder,ParserFactory,PrettyPrinter};
/** Execute source-derived scalar projection bodies on artificial objects; no native graph is loaded. */
final class CircleProfileProjectionTest extends TestCase {
    private function method(string $class,string $name):Node\Stmt\ClassMethod {
        $tree=(new ParserFactory())->createForNewestSupportedVersion()->parse(file_get_contents(dirname(__DIR__).'/src/Service/CircleDetached/'.$class.'.php'));
        $method=(new NodeFinder())->findFirst($tree,static fn(Node$n):bool=>$n instanceof Node\Stmt\ClassMethod&&$n->name->toString()===$name);self::assertInstanceOf(Node\Stmt\ClassMethod::class,$method);return$method;
    }
    private function body(string $class,string $method):string {return(new PrettyPrinter\Standard())->prettyPrint($this->method($class,$method)->stmts);}
    public function testProfileUsesNativeDetailQueryWithoutHydratingMembership():void {
        $circle=new class {public int $memberCalls=0;public function getSingleId():string{return'fixture-id';}public function getDisplayName():string{return'Fixture';}public function getUrl():string{return'https://local.invalid/team';}public function getInheritedMembers():array{$this->memberCalls++;throw new LogicException('Unrelated member hydration');}};
        $request=new class($circle) {public array $calls=[];public function __construct(private object $circle){}public function getCircle(string$id,mixed$initiator):object{$this->calls[]=[$id,$initiator];return$this->circle;}};
        $scope=new class($request) {public int $checks=0;public function __construct(public object $circles){}public function checkpoint():void{$this->checks++;}};
        $profile=(eval('return function(string $id):array {'.$this->body('Lease','profile').'};'))->bindTo($scope,$scope::class);
        self::assertSame(['id'=>'fixture-id','display_name'=>'Fixture','url'=>'https://local.invalid/team'],$profile('fixture-id'));self::assertSame([['fixture-id',null]],$request->calls);self::assertSame(0,$circle->memberCalls);self::assertSame(1,$scope->checks);
    }
    public function testCircleViewUsesTheProfileRead():void {
        $method=$this->method('Reader','circleView');$calls=(new NodeFinder())->findInstanceOf($method->stmts,Node\Expr\MethodCall::class);self::assertSame(['profile'],array_map(static fn($call):string=>$call->name->toString(),$calls));
    }
    public function testProfileOnlyViewCannotFabricateEmptyMembership():void {
        $scope=new class {public array $data=['id'=>'fixture-id','display_name'=>'Fixture','url'=>''];};
        $get=(eval('return function(bool $detailed=false,bool $remote=false):array {'.$this->body('CircleView','getInheritedMembers').'};'))->bindTo($scope,$scope::class);
        $this->expectException(RuntimeException::class);$get();
    }
    public function testExplicitFullEmptyProjectionRemainsUsable():void {
        $scope=new class {public array $data=['members'=>[]];};$get=(eval('return function(bool $detailed=false,bool $remote=false):array {'.$this->body('CircleView','getInheritedMembers').'};'))->bindTo($scope,$scope::class);self::assertSame([],$get());
    }
}
