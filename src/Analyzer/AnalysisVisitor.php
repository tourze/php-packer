<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer;

use PhpPacker\Analyzer\Processor\DependencyProcessor;
use PhpPacker\Analyzer\Processor\IncludeProcessor;
use PhpPacker\Analyzer\Processor\NodeClassificationProcessor;
use PhpPacker\Analyzer\Processor\SymbolProcessor;
use PhpPacker\Storage\SqliteStorage;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class AnalysisVisitor extends NodeVisitorAbstract
{
    private NodeClassificationProcessor $nodeClassifier;

    private SymbolProcessor $symbolProcessor;

    private DependencyProcessor $dependencyProcessor;

    private IncludeProcessor $includeProcessor;

    private bool $inConditionalContext = false;

    /**
     * @phpstan-ignore-next-line constructor.unusedParameter
     */
    public function __construct(SqliteStorage $storage, int $fileId, ?string $namespace, string $filePath)
    {
        $this->nodeClassifier = new NodeClassificationProcessor();
        $this->symbolProcessor = new SymbolProcessor($storage, $fileId, $namespace);
        $this->dependencyProcessor = new DependencyProcessor($storage, $fileId);
        $this->includeProcessor = new IncludeProcessor($storage, $fileId);
    }

    public function enterNode(Node $node)
    {
        $this->processNodeByType($node);

        return null;
    }

    private function processNodeByType(Node $node): void
    {
        if ($this->nodeClassifier->isNamespaceNode($node)) {
            assert($node instanceof Node\Stmt\Namespace_);
            $this->processNamespace($node);
        } elseif ($this->nodeClassifier->isUseNode($node)) {
            assert($node instanceof Node\Stmt\Use_);
            $this->dependencyProcessor->processUseStatement($node);
        } elseif ($this->nodeClassifier->isGroupUseNode($node)) {
            assert($node instanceof Node\Stmt\GroupUse);
            $this->dependencyProcessor->processGroupUseStatement($node);
        } else {
            $this->processOtherNodes($node);
        }
    }

    private function processOtherNodes(Node $node): void
    {
        if ($this->nodeClassifier->isClassNode($node)) {
            assert($node instanceof Node\Stmt\Class_);
            $this->processClass($node);
        } elseif ($this->nodeClassifier->isInterfaceNode($node)) {
            assert($node instanceof Node\Stmt\Interface_);
            $this->processInterface($node);
        } elseif ($this->nodeClassifier->isTraitNode($node)) {
            assert($node instanceof Node\Stmt\Trait_);
            $this->processTrait($node);
        } elseif ($this->nodeClassifier->isFunctionNode($node)) {
            assert($node instanceof Node\Stmt\Function_);
            $this->processFunction($node);
        } else {
            $this->processExpressionNodes($node);
        }
    }

    private function processExpressionNodes(Node $node): void
    {
        if ($this->nodeClassifier->isConditionalNode($node)) {
            $this->inConditionalContext = true;
            $this->dependencyProcessor->setConditionalContext(true);
        } elseif ($this->nodeClassifier->isIncludeNode($node)) {
            assert($node instanceof Node\Expr\Include_);
            $this->includeProcessor->processInclude($node, $this->inConditionalContext);
        } elseif ($this->nodeClassifier->isNewInstanceNode($node)) {
            assert($node instanceof Node\Expr\New_);
            $this->dependencyProcessor->processNewInstance($node);
        } elseif ($this->nodeClassifier->isStaticReferenceNode($node)) {
            $this->dependencyProcessor->processStaticReference($node);
        }
    }

    private function processNamespace(Node\Stmt\Namespace_ $node): void
    {
        $namespace = null !== $node->name ? $node->name->toString() : null;
        $this->symbolProcessor->setCurrentNamespace($namespace);
    }

    private function processClass(Node\Stmt\Class_ $node): void
    {
        // 跳过匿名类，因为它们已经在 processNewInstance 中处理过了
        if (null === $node->name) {
            return;
        }

        $this->symbolProcessor->processClass($node);
        $this->dependencyProcessor->processClassDependencies($node);
    }

    private function processInterface(Node\Stmt\Interface_ $node): void
    {
        $this->symbolProcessor->processInterface($node);
        $this->dependencyProcessor->processInterfaceExtends($node);
    }

    private function processTrait(Node\Stmt\Trait_ $node): void
    {
        $this->symbolProcessor->processTrait($node);
    }

    private function processFunction(Node\Stmt\Function_ $node): void
    {
        $this->symbolProcessor->processFunction($node);
    }

    public function getSymbolCount(): int
    {
        return $this->symbolProcessor->getSymbolCount();
    }

    public function getDependencyCount(): int
    {
        return $this->dependencyProcessor->getDependencyCount()
            + $this->includeProcessor->getDependencyCount();
    }
}
