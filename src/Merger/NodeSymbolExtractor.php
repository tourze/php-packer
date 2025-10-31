<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

use PhpParser\Node;

class NodeSymbolExtractor
{
    public function getNodeSymbol(Node $node): ?string
    {
        if ($this->isClassLikeNode($node)) {
            return $this->getClassLikeSymbol($node);
        }

        if ($node instanceof Node\Stmt\Function_) {
            return $this->getFunctionSymbol($node);
        }

        if ($node instanceof Node\Stmt\Const_) {
            return $this->getConstNodeSymbol($node);
        }

        if ($this->isImportNode($node)) {
            return $this->getImportSymbol($node);
        }

        if ($node instanceof Node\Stmt\Namespace_) {
            return $this->getNamespaceSymbol($node);
        }

        return null;
    }

    public function getNodeType(Node $node): string
    {
        return match (true) {
            $node instanceof Node\Stmt\Class_ => 'class',
            $node instanceof Node\Stmt\Interface_ => 'interface',
            $node instanceof Node\Stmt\Trait_ => 'trait',
            $node instanceof Node\Stmt\Function_ => 'function',
            $node instanceof Node\Stmt\Const_ => 'const',
            default => 'other',
        };
    }

    private function isClassLikeNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Class_
            || $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_;
    }

    private function getClassLikeSymbol(Node $node): string
    {
        return match (true) {
            $node instanceof Node\Stmt\Class_ => $this->getClassSymbol($node),
            $node instanceof Node\Stmt\Interface_ => $this->getInterfaceSymbol($node),
            $node instanceof Node\Stmt\Trait_ => $this->getTraitSymbol($node),
            default => '',
        };
    }

    private function isImportNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Use_
            || $node instanceof Node\Stmt\GroupUse;
    }

    private function getImportSymbol(Node $node): ?string
    {
        return match (true) {
            $node instanceof Node\Stmt\Use_ => $this->getUseNodeSymbol($node),
            $node instanceof Node\Stmt\GroupUse => $this->getGroupUseNodeSymbol($node),
            default => null,
        };
    }

    private function getClassSymbol(Node\Stmt\Class_ $node): string
    {
        return isset($node->namespacedName)
            ? $node->namespacedName->toString()
            : ($node->name?->toString() ?? '');
    }

    private function getInterfaceSymbol(Node\Stmt\Interface_ $node): string
    {
        return isset($node->namespacedName)
            ? $node->namespacedName->toString()
            : ($node->name?->toString() ?? '');
    }

    private function getTraitSymbol(Node\Stmt\Trait_ $node): string
    {
        return isset($node->namespacedName)
            ? $node->namespacedName->toString()
            : ($node->name?->toString() ?? '');
    }

    private function getFunctionSymbol(Node\Stmt\Function_ $node): string
    {
        return isset($node->namespacedName)
            ? $node->namespacedName->toString()
            : $node->name->toString();
    }

    private function getConstNodeSymbol(Node\Stmt\Const_ $node): ?string
    {
        if (0 === count($node->consts)) {
            return null;
        }

        $const = $node->consts[0];

        return isset($const->namespacedName)
            ? $const->namespacedName->toString()
            : $const->name->toString();
    }

    private function getUseNodeSymbol(Node\Stmt\Use_ $node): string
    {
        $useNames = [];
        foreach ($node->uses as $use) {
            $useNames[] = $use->name->toString() .
                         (null !== $use->alias ? ' as ' . $use->alias->toString() : '');
        }

        return 'use:' . implode(',', $useNames);
    }

    private function getGroupUseNodeSymbol(Node\Stmt\GroupUse $node): string
    {
        $prefix = $node->prefix->toString();
        $useNames = [];
        foreach ($node->uses as $use) {
            $useNames[] = $prefix . '\\' . $use->name->toString() .
                         (null !== $use->alias ? ' as ' . $use->alias->toString() : '');
        }

        return 'use:' . implode(',', $useNames);
    }

    private function getNamespaceSymbol(Node\Stmt\Namespace_ $node): string
    {
        return null !== $node->name ? $node->name->toString() : '';
    }
}
