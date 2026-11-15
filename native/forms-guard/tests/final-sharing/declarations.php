<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
require (require __DIR__.'/repository-root.php').'/mcp/vendor/autoload.php';
use PhpParser\{Node,NodeTraverser,NodeVisitorAbstract,ParserFactory};
$parser=(new ParserFactory)->createForNewestSupportedVersion();$printer=new PhpParser\PrettyPrinter\Standard();$sources=[];$candidates=[];
$strip=new class extends NodeVisitorAbstract {
    public function enterNode(Node $node) {
        if ($node instanceof Node\Stmt\ClassMethod && $node->stmts!==null) {$node->stmts=[new Node\Stmt\Expression(new Node\Expr\Exit_(new Node\Scalar\Int_(97)))];}
        if ($node instanceof Node\Stmt\Function_) {return NodeTraverser::REMOVE_NODE;}
        return null;
    }
};
foreach (glob(dirname(__DIR__).'/references/*.php') as $file) {
    if(str_contains(basename($file),'__AppInfo__'))continue;
    $ast=$parser->parse(file_get_contents($file));$traverser=new NodeTraverser();$traverser->addVisitor(new PhpParser\NodeVisitor\NameResolver());$traverser->addVisitor($strip);$ast=$traverser->traverse($ast);
    foreach ((new PhpParser\NodeFinder)->findInstanceOf($ast,Node\Stmt\ClassLike::class) as $class) {if(isset($class->namespacedName))$sources[$class->namespacedName->toString()]=$printer->prettyPrintFile($ast);}
}
foreach ([...glob(dirname(__DIR__).'/src/*.php'),...glob(dirname(__DIR__).'/adapters/*.php'),...glob(dirname(__DIR__).'/standalone/*.php'),...glob(dirname(__DIR__).'/migration/*.php')] as $file) {
    $ast=$parser->parse(file_get_contents($file));$traverser=new NodeTraverser();$traverser->addVisitor(new PhpParser\NodeVisitor\NameResolver());$ast=$traverser->traverse($ast);
    foreach ((new PhpParser\NodeFinder)->findInstanceOf($ast,Node\Stmt\ClassLike::class) as $class) {if(isset($class->namespacedName))$candidates[$class->namespacedName->toString()]=$printer->prettyPrintFile($ast);}
}
$loaded=[];
spl_autoload_register(static function(string $class) use ($sources,$candidates,&$loaded):void {
    $code=$candidates[$class]??$sources[$class]??null;
    if ($code===null) {throw new RuntimeException('Missing declaration: '.$class);}
    eval(substr($code,5));$loaded[]=$class;
},true,true);
foreach (array_keys($candidates) as $class) {if(!class_exists($class))throw new RuntimeException('Candidate declaration missing');}
foreach(['OC\\Files\\Cache\\Cache','OC\\Files\\Cache\\Wrapper\\CachePermissionsMask','OC\\Files\\Cache\\CacheQueryBuilder','OC\\Files\\Type\\Loader']as$class)if(!class_exists($class))throw new RuntimeException('Native closure declaration missing');
echo json_encode(['declarations_only'=>true,'candidate_classes'=>count($candidates),'native_loaded'=>count($loaded)-count($candidates),'method_invocations'=>0],JSON_THROW_ON_ERROR)."\n";
