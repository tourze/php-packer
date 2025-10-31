<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

use PhpParser\Node;
use Psr\Log\LoggerInterface;

class DuplicationHandler
{
    public function __construct(
        private LoggerInterface $logger,
        private NodeSymbolExtractor $symbolExtractor,
    ) {
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function handleDuplicateSymbol(Node $node, string $symbol, array $state): array
    {
        if ($node instanceof Node\Stmt\Function_) {
            return $this->handleDuplicateFunction($node, $symbol, $state);
        }
        if ($node instanceof Node\Stmt\Class_) {
            return $this->handleDuplicateClass($node, $symbol, $state);
        }
        $this->logger->debug('Skipping duplicate symbol', ['symbol' => $symbol]);

        return $state;
    }

    public function areClassesEqual(Node\Stmt\Class_ $class1, Node\Stmt\Class_ $class2): bool
    {
        /** @var array<Node\Stmt\ClassMethod> $methods1 */
        $methods1 = array_filter($class1->stmts ?? [], fn ($stmt) => $stmt instanceof Node\Stmt\ClassMethod);
        /** @var array<Node\Stmt\ClassMethod> $methods2 */
        $methods2 = array_filter($class2->stmts ?? [], fn ($stmt) => $stmt instanceof Node\Stmt\ClassMethod);

        if (count($methods1) !== count($methods2)) {
            return false;
        }

        $methodNames1 = array_map(fn (Node\Stmt\ClassMethod $method) => $method->name->toString(), $methods1);
        $methodNames2 = array_map(fn (Node\Stmt\ClassMethod $method) => $method->name->toString(), $methods2);

        sort($methodNames1);
        sort($methodNames2);

        return $methodNames1 === $methodNames2;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function handleDuplicateClass(Node\Stmt\Class_ $node, string $symbol, array $state): array
    {
        $existingClass = $this->findExistingClass($symbol, $state);

        if (null === $existingClass) {
            $state['uniqueNodes'][] = $node;

            return $state;
        }

        if ($this->areClassesEqual($existingClass, $node)) {
            $this->logger->debug('Skipping duplicate class definition with same content', ['symbol' => $symbol]);
        } else {
            $this->logger->debug('Keeping different class definition with same name', ['symbol' => $symbol]);
            $state['uniqueNodes'][] = $node;
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function findExistingClass(string $symbol, array $state): ?Node\Stmt\Class_
    {
        foreach ($state['uniqueNodes'] as $existingNode) {
            if ($existingNode instanceof Node\Stmt\Class_ && $this->getClassSymbol($existingNode) === $symbol) {
                return $existingNode;
            }
        }

        return null;
    }

    private function getClassSymbol(Node\Stmt\Class_ $node): string
    {
        return isset($node->namespacedName)
            ? $node->namespacedName->toString()
            : ($node->name?->toString() ?? '');
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function handleDuplicateFunction(Node\Stmt\Function_ $node, string $symbol, array $state): array
    {
        if (!isset($state['conditionalFunctions'][$symbol])) {
            $state = $this->moveExistingFunctionToConditional($symbol, $state);
        }
        $state['conditionalFunctions'][$symbol][] = $node;

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function moveExistingFunctionToConditional(string $symbol, array $state): array
    {
        foreach ($state['uniqueNodes'] as $i => $existingNode) {
            if ($this->symbolExtractor->getNodeSymbol($existingNode) === $symbol) {
                $state['conditionalFunctions'][$symbol][] = $existingNode;
                unset($state['uniqueNodes'][$i]);
                break;
            }
        }

        return $state;
    }
}
