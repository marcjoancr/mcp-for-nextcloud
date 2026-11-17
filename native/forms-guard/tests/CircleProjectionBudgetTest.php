<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

use PhpParser\{Node, NodeFinder, ParserFactory, PrettyPrinter};
use PHPUnit\Framework\TestCase;

/** Execute the exact bounded projection methods without loading the native Circle graph or a database. */
final class CircleProjectionBudgetTest extends TestCase {
    private function methods(): array {
        $path=dirname(__DIR__).'/src/Service/CircleDetached/CoreQueryBuilder.php';
        $tree=(new ParserFactory())->createForNewestSupportedVersion()->parse(file_get_contents($path));
        $methods=[];
        foreach((new NodeFinder())->findInstanceOf($tree,Node\Stmt\ClassMethod::class)as$method){$methods[$method->name->toString()]=$method;}
        return$methods;
    }

    private function projection(string $platformName): array {
        $methods=$this->methods();
        $printer=new PrettyPrinter\Standard();
        $budgetColumn=eval('return function(string $column,string $field):string {'.$printer->prettyPrint($methods['budgetColumn']->stmts).'};');
        $body=$printer->prettyPrint($methods['boundProjection']->stmts);
        $body=str_replace('self::UNBOUNDED_TEXT',"['settings','description','note','contact_meta','inheritance_path','href','item','attributes']",$body);
        $boundProjection=eval('return function():void {'.$body.'};');

        $db=new class($platformName) {public function __construct(private string $provider){} public function getDatabaseProvider(bool $strict=false):string{return$this->provider;}};
        $lease=new class($db) {public function __construct(private object $db){} public function db():object{return$this->db;}};
        $scope=new class($lease,$budgetColumn) {
            public array $ownedProjection=[['r.attributes','attributes','r_attributes'],['r.description','description','r_description']];
            public array $selected=[];
            public function __construct(public object $__formsCircleLease,private Closure $budgetColumn){}
            public function getQueryPart(string $part):array{return$part==='select'?array_fill(0,count($this->ownedProjection),null):[];}
            public function budgetColumn(string $column,string $field):string{return($this->budgetColumn)->call($this,$column,$field);}
            public function createFunction(string $expression):string{return$expression;}
            public function select(array $expressions):void{$this->selected=$expressions;}
        };
        $boundProjection=$boundProjection->bindTo($scope,$scope::class);
        $boundProjection();
        return$scope->selected;
    }

    public function testPostgresJsonAttributesAreCastToTextBeforeByteBudgetAndOverflowCase():void {
        [$attributes,$description]=$this->projection('postgres');
        self::assertStringContainsString("OCTET_LENGTH(COALESCE(CAST(r.attributes AS TEXT),''))",$attributes);
        self::assertStringContainsString("OCTET_LENGTH(COALESCE(r.description,''))",$attributes);
        self::assertStringContainsString("THEN '__forms_circle_row_budget_exceeded__' ELSE CAST(r.attributes AS TEXT) END",$attributes);
        self::assertStringContainsString('>1000000',$attributes);
        self::assertStringContainsString('CASE WHEN',$description);
    }

    public function testNonPostgresTextAttributesKeepTheNativeColumnType():void {
        [$attributes]=$this->projection('mariadb');
        self::assertStringContainsString("OCTET_LENGTH(COALESCE(r.attributes,''))",$attributes);
        self::assertStringContainsString("THEN '__forms_circle_row_budget_exceeded__' ELSE r.attributes END",$attributes);
        self::assertStringNotContainsString('CAST(r.attributes AS TEXT)',$attributes);
    }
}
