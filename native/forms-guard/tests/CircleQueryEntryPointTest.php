<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use PhpParser\{Node,NodeFinder,ParserFactory,PrettyPrinter};
/** Source-derived pure guard execution: no native declaration, query object, lease or database is loaded. */
final class CircleQueryEntryPointTest extends TestCase {
    private function methods():array {
        $tree=(new ParserFactory())->createForNewestSupportedVersion()->parse(file_get_contents(dirname(__DIR__).'/src/Service/CircleDetached/CoreQueryBuilder.php'));
        $methods=[];foreach((new NodeFinder())->findInstanceOf($tree,Node\Stmt\ClassMethod::class)as$method){$methods[$method->name->toString()]=$method;}return$methods;
    }
    public function testPublicQueryExecutionRefusesWithoutAnObjectOrDatabase():void {
        $method=$this->methods()['executeQuery']??null;self::assertInstanceOf(Node\Stmt\ClassMethod::class,$method);
        // Execute the exact candidate guard body in an unbound closure. Any attempted object/dependency access cannot work.
        $guard=eval('return static function() {'.(new PrettyPrinter\Standard())->prettyPrint($method->stmts).'};');
        $this->expectException(RuntimeException::class);$guard();
    }
    public function testConnectionAndSequenceEntryPointsRefuseWithoutDependencies():void {
        $methods=$this->methods();
        foreach(['getConnection','getLastInsertId']as$name){
            $method=$methods[$name]??null;self::assertInstanceOf(Node\Stmt\ClassMethod::class,$method);
            $guard=eval('return static function() {'.(new PrettyPrinter\Standard())->prettyPrint($method->stmts).'};');
            try{$guard();self::fail('Public database capability escaped');}catch(RuntimeException){self::assertTrue(true);}
        }
    }
    public function testNativeOptionalConnectionSignatureIsPreserved():void {
        $method=$this->methods()['executeQuery']??null;self::assertInstanceOf(Node\Stmt\ClassMethod::class,$method);self::assertTrue($method->isPublic());self::assertCount(1,$method->params);
        $param=$method->params[0];self::assertSame('connection',$param->var->name);self::assertInstanceOf(Node\NullableType::class,$param->type);self::assertSame('OCP\\IDBConnection',$param->type->type->toString());self::assertSame('null',$param->default->name->toString());self::assertSame('OCP\\DB\\IResult',$method->returnType->toString());
    }
    public function testOnlyPrivateBoundedReaderCallsNativeExecution():void {
        $methods=$this->methods();$calls=[];
        foreach($methods as$name=>$method){foreach((new NodeFinder())->findInstanceOf($method->stmts??[],Node\Expr\StaticCall::class)as$call){if($call->class instanceof Node\Name&&$call->class->toString()==='parent'&&$call->name instanceof Node\Identifier&&$call->name->toString()==='executeQuery'){$calls[]=$name;}}}
        self::assertSame(['rawInheritedUsers','rawPage'],$calls);foreach($calls as$name){self::assertTrue($methods[$name]->isPrivate());}
    }
}
