<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer\Processor;

use PhpPacker\Storage\SqliteStorage;
use PhpParser\Node;

class IncludeProcessor
{
    private SqliteStorage $storage;

    private int $fileId;

    private int $dependencyCount = 0;

    public function __construct(SqliteStorage $storage, int $fileId)
    {
        $this->storage = $storage;
        $this->fileId = $fileId;
    }

    public function processInclude(Node\Expr\Include_ $node, bool $inConditionalContext): void
    {
        $type = match ($node->type) {
            Node\Expr\Include_::TYPE_INCLUDE => 'include',
            Node\Expr\Include_::TYPE_INCLUDE_ONCE => 'include_once',
            Node\Expr\Include_::TYPE_REQUIRE => 'require',
            Node\Expr\Include_::TYPE_REQUIRE_ONCE => 'require_once',
            default => 'include',
        };

        $context = $this->extractIncludeContext($node);
        $isConditional = $this->isConditionalInclude($node, $inConditionalContext);

        $this->storage->addDependency(
            $this->fileId,
            $type,
            null,
            $node->getStartLine(),
            $isConditional,
            $context
        );
        ++$this->dependencyCount;
    }

    private function extractIncludeContext(Node\Expr\Include_ $node): string
    {
        if ($node->expr instanceof Node\Scalar\String_) {
            return $node->expr->value;
        }
        if ($node->expr instanceof Node\Expr\BinaryOp\Concat) {
            $resolved = $this->resolveConcatExpression($node->expr);

            return null !== $resolved ? $resolved : 'dynamic';
        }

        return 'complex';
    }

    private function resolveConcatExpression(Node\Expr\BinaryOp\Concat $node): ?string
    {
        $left = $this->resolveExpressionValue($node->left);
        $right = $this->resolveExpressionValue($node->right);

        if (null !== $left && null !== $right) {
            return $left . $right;
        }

        return null;
    }

    private function resolveExpressionValue(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }
        if ($expr instanceof Node\Scalar\MagicConst\Dir) {
            return '__DIR__';
        }
        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            return $this->resolveConcatExpression($expr);
        }

        return null;
    }

    private function isConditionalInclude(Node $node, bool $inConditionalContext): bool
    {
        return $inConditionalContext;
    }

    public function getDependencyCount(): int
    {
        return $this->dependencyCount;
    }
}
