<?php

namespace PhpPacker\Parser;

use PhpPacker\Visitor\RenameDebugInfoVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use Psr\Log\LoggerInterface;

class AstManager
{
    private array $astMap = [];
    private LoggerInterface $logger;
    
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
    
    public function addAst(string $file, array $ast): void
    {
        $this->astMap[$file] = $ast;
//        $this->logger->debug('Added AST for file', [
//            'file' => $file,
//            //'nodes' => count($ast)
//        ]);
    }
    
    public function getAst(string $file): ?array
    {
        return $this->astMap[$file] ?? null;
    }
    
    public function hasAst(string $file): bool
    {
        return isset($this->astMap[$file]);
    }
    
    public function getAllAsts(): array
    {
        return $this->astMap;
    }
    
    public function getFileCount(): int
    {
        return count($this->astMap);
    }
    
    public function getTotalNodeCount(): int
    {
        $count = 0;
        foreach ($this->astMap as $ast) {
            $count += count($ast);
        }
        return $count;
    }
    
    public function clear(): void
    {
        $this->astMap = [];
        $this->logger->debug('Cleared all ASTs');
    }

    public function createNodeTraverser(): NodeTraverser
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver()); // 全部改为 fqcn 风格
        $traverser->addVisitor(new RenameDebugInfoVisitor()); // 全部改为 fqcn 风格
        return $traverser;
    }
}
