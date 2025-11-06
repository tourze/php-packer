<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer\Processor;

use PhpPacker\Storage\StorageInterface;
use PhpParser\Node;

class SymbolProcessor
{
    private StorageInterface $storage;

    private int $fileId;

    private ?string $currentNamespace;

    private int $symbolCount = 0;

    public function __construct(StorageInterface $storage, int $fileId, ?string $namespace)
    {
        $this->storage = $storage;
        $this->fileId = $fileId;
        $this->currentNamespace = $namespace;
    }

    public function processClass(Node\Stmt\Class_ $node): void
    {
        if (null === $node->name) {
            return;
        }

        $className = $node->name->toString();
        $fqn = $this->buildFqn($className);

        $this->storage->addSymbol(
            $this->fileId,
            'class',
            $className,
            $fqn,
            $this->currentNamespace,
            $this->getVisibility($node)
        );
        ++$this->symbolCount;
    }

    private function buildFqn(string $name): string
    {
        return null !== $this->currentNamespace ? $this->currentNamespace . '\\' . $name : $name;
    }

    private function getVisibility(Node\Stmt\Class_ $node): string
    {
        if ($node->isAbstract()) {
            return 'abstract';
        }
        if ($node->isFinal()) {
            return 'final';
        }

        return 'public';
    }

    public function processInterface(Node\Stmt\Interface_ $node): void
    {
        $interfaceName = $node->name?->toString() ?? '';
        $fqn = $this->buildFqn($interfaceName);

        $this->storage->addSymbol(
            $this->fileId,
            'interface',
            $interfaceName,
            $fqn,
            $this->currentNamespace
        );
        ++$this->symbolCount;
    }

    public function processTrait(Node\Stmt\Trait_ $node): void
    {
        $traitName = $node->name?->toString() ?? '';
        $fqn = $this->buildFqn($traitName);

        $this->storage->addSymbol(
            $this->fileId,
            'trait',
            $traitName,
            $fqn,
            $this->currentNamespace
        );
        ++$this->symbolCount;
    }

    public function processFunction(Node\Stmt\Function_ $node): void
    {
        $functionName = $node->name->toString();
        $fqn = null !== $this->currentNamespace ? $this->currentNamespace . '\\' . $functionName : $functionName;

        $this->storage->addSymbol(
            $this->fileId,
            'function',
            $functionName,
            $fqn,
            $this->currentNamespace
        );
        ++$this->symbolCount;
    }

    public function setCurrentNamespace(?string $namespace): void
    {
        $this->currentNamespace = $namespace;
    }

    public function getSymbolCount(): int
    {
        return $this->symbolCount;
    }
}
