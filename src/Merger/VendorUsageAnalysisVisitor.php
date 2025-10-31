<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class VendorUsageAnalysisVisitor extends NodeVisitorAbstract
{
    /** @var array<string> */
    private array $usedMethods = [];

    /** @var array<string> */
    private array $usedProperties = [];

    public function __construct()
    {
    }

    /**
     * @return array{methods: array<string>, properties: array<string>}
     */
    public function getUsageData(): array
    {
        return [
            'methods' => array_unique($this->usedMethods),
            'properties' => array_unique($this->usedProperties),
        ];
    }

    public function enterNode(Node $node): ?int
    {
        $this->extractMethodCalls($node);
        $this->extractPropertyAccess($node);

        return null;
    }

    private function extractMethodCalls(Node $node): void
    {
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $methodName = $node->name->toString();
            if (!in_array($methodName, $this->usedMethods, true)) {
                $this->usedMethods[] = $methodName;
            }
        }
    }

    private function extractPropertyAccess(Node $node): void
    {
        if ($node instanceof Node\Expr\PropertyFetch && $node->name instanceof Node\Identifier) {
            $propertyName = $node->name->toString();
            if (!in_array($propertyName, $this->usedProperties, true)) {
                $this->usedProperties[] = $propertyName;
            }
        }
    }
}
