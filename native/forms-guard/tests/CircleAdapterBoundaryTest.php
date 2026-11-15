<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use PhpParser\{Node,NodeFinder,ParserFactory};
final class CircleAdapterBoundaryTest extends TestCase {
    private function tree(string$file):array {self::assertFileExists($file);return(new ParserFactory)->createForNewestSupportedVersion()->parse(file_get_contents($file));}
    public function testScopedAdapterExplicitlyRoutesFullUsersAndDoesNotInheritMemberViewFallback():void {
        $tree=$this->tree(dirname(__DIR__).'/src/Service/ScopedCircles.php');$method=null;foreach((new NodeFinder)->findInstanceOf($tree,Node\Stmt\ClassMethod::class)as$m){if($m->name->toString()==='getCircleUsers')$method=$m;}
        self::assertNotNull($method);$strings=(new NodeFinder)->findInstanceOf($method->stmts,Node\Scalar\String_::class);self::assertSame(['getCircleUsers','circleId'],array_map(static fn($n)=>$n->value,$strings));
    }
    public function testOnlyNativeMissingEntityExceptionsCanBecomeEmptyResults():void {
        $tree=$this->tree(dirname(__DIR__).'/src/Service/CircleReadRuntime.php');$caught=[];foreach((new NodeFinder)->findInstanceOf($tree,Node\Stmt\Catch_::class)as$c){foreach($c->types as$t)$caught[]=$t->toString();}
        self::assertSame(['OCA\\Circles\\Exceptions\\CircleNotFoundException'],$caught);
    }
    public function testNativeGlobalHookRequiresAnActualManagedProducerFrame():void {
        $source=file_get_contents(dirname(__DIR__).'/prepare.mjs');self::assertStringContainsString('CircleReadRuntime::managedProducerActive()', $source);self::assertStringNotContainsString('CircleReadRuntime::native(', $source);
    }
}
