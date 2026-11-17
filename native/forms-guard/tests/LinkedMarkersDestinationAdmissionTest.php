<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class LinkedMarkersDestinationAdmissionTest extends TestCase {
    /** @return array<string, Node\Stmt\ClassMethod> */
    private function methods(): array {
        $path=dirname(__DIR__).'/src/Service/LinkedMarkers.php';
        $tree=(new ParserFactory())->createForNewestSupportedVersion()->parse(file_get_contents($path));
        $finder=new NodeFinder();
        $class=$finder->findFirst($tree,static fn(Node $node):bool=>$node instanceof Node\Stmt\Class_&&$node->name?->toString()==='LinkedMarkers');
        self::assertInstanceOf(Node\Stmt\Class_::class,$class);
        $methods=[];
        foreach($class->stmts as $statement)if($statement instanceof Node\Stmt\ClassMethod)$methods[$statement->name->toString()]=$statement;
        return $methods;
    }

    /** @return list<Node\Expr\StaticCall> */
    private function staticCalls(Node\Stmt\ClassMethod $method): array {
        return (new NodeFinder())->findInstanceOf($method->stmts??[],Node\Expr\StaticCall::class);
    }

    public function testSharedOutputVerificationUsesTheSignedOriginAndExactFile(): void {
        $methods=$this->methods();
        self::assertArrayHasKey('admitLinkedDestination',$methods,'Shared output admission helper is missing');
        self::assertArrayHasKey('verifyFile',$methods);
        self::assertArrayHasKey('assertWritingOutput',$methods);
        $helper=$this->staticCalls($methods['admitLinkedDestination']);
        $calls=[];
        foreach($helper as $call)if($call->class instanceof Node\Name&&$call->name instanceof Node\Identifier)$calls[$call->class->toString().'::'.$call->name->toString()]=true;
        self::assertArrayHasKey('OriginContext::within',$calls);
        self::assertArrayHasKey('OriginContext::assertFiles',$calls);
        self::assertArrayHasKey('StorageAdmission::linkedDestination',$calls);

        foreach(['verifyFile','assertWritingOutput'] as $name) {
            $found=false;
            foreach((new NodeFinder())->findInstanceOf($methods[$name]->stmts??[],Node\Expr\MethodCall::class) as $call) {
                if($call->name instanceof Node\Identifier&&$call->name->toString()==='admitLinkedDestination')$found=true;
            }
            self::assertTrue($found,$name.' bypasses origin-bound destination admission');
        }
        foreach($this->staticCalls($methods['verifyFile']) as $call) {
            if($call->class instanceof Node\Name&&$call->class->toString()==='StorageAdmission'
                && $call->name instanceof Node\Identifier) {
                self::assertNotContains($call->name->toString(),['node','path'],'Linked output verification used generic storage admission');
            }
        }
    }

    public function testGenericNodeAdmissionStillChecksStorageAndPath(): void {
        $tree=(new ParserFactory())->createForNewestSupportedVersion()->parse(file_get_contents(dirname(__DIR__).'/src/Service/StorageAdmission.php'));
        $finder=new NodeFinder();
        $class=$finder->findFirst($tree,static fn(Node $node):bool=>$node instanceof Node\Stmt\Class_&&$node->name?->toString()==='StorageAdmission');
        self::assertInstanceOf(Node\Stmt\Class_::class,$class);
        $node=null;
        foreach($class->stmts as $statement)if($statement instanceof Node\Stmt\ClassMethod&&$statement->name->toString()==='node')$node=$statement;
        self::assertInstanceOf(Node\Stmt\ClassMethod::class,$node);
        $calls=[];
        foreach($this->staticCalls($node) as $call)if($call->class instanceof Node\Name&&$call->class->toString()==='self'&&$call->name instanceof Node\Identifier)$calls[]=$call->name->toString();
        self::assertContains('storage',$calls);
        self::assertContains('path',$calls);
    }
}
