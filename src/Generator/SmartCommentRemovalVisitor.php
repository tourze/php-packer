<?php

declare(strict_types=1);

namespace PhpPacker\Generator;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * 智能注释移除访问器
 */
class SmartCommentRemovalVisitor extends NodeVisitorAbstract
{
    /**
     * 进入节点时的处理
     */
    public function enterNode(Node $node)
    {
        // 移除所有注释
        $node->setAttribute('comments', []);

        // 对于方法和函数，检查是否需要保留某些注释
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            $docComment = $node->getDocComment();
            if (null !== $docComment) {
                // 检查是否有必要保留的注释
                $comment = $docComment->getText();
                $necessaryComment = $this->filterNecessaryComment($comment, $node);

                if ('' !== $necessaryComment) {
                    // 创建新的注释节点
                    $newComment = new Doc($necessaryComment);
                    $node->setDocComment($newComment);
                }
            }
        }

        return null;
    }

    /**
     * 过滤出必要的注释内容
     */
    private function filterNecessaryComment(string $comment, Node $node): string
    {
        if (!$this->isValidNode($node)) {
            return '';
        }

        $lines = explode("\n", $comment);
        /** @var Node\Stmt\Function_|Node\Stmt\ClassMethod $node */
        $paramTypes = $this->getParamTypes($node->params);
        $returnType = $node->returnType;

        $filteredLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line, " \t*");

            if ($this->shouldKeepLine($trimmed, $paramTypes, $returnType)) {
                $filteredLines[] = $line;
            }
        }

        return 0 === count($filteredLines) ? '' : implode("\n", $filteredLines);
    }

    private function isValidNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod;
    }

    /**
     * @param array<int, Node\Param> $params
     * @return array<string, bool>
     */
    private function getParamTypes(array $params): array
    {
        $paramTypes = [];
        foreach ($params as $param) {
            if (null !== $param->type && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $paramTypes[$param->var->name] = true;
            }
        }

        return $paramTypes;
    }

    /**
     * @param array<string, bool> $paramTypes
     */
    private function shouldKeepLine(string $trimmed, array $paramTypes, ?Node $returnType): bool
    {
        if ('' === $trimmed || '/' === $trimmed) {
            return false;
        }

        // 保留描述行（不是 @param 或 @return）
        if (!str_starts_with($trimmed, '@')) {
            return true;
        }

        // 检查 @param 注解
        if (1 === preg_match('/@param\s+(\S+)\s+\$(\w+)/', $trimmed, $matches)) {
            $paramName = $matches[2];

            return !isset($paramTypes[$paramName]);
        }

        // 检查 @return 注解
        if (str_starts_with($trimmed, '@return')) {
            return null === $returnType;
        }

        // 保留其他重要注解
        return (bool) preg_match('/@(throws|deprecated|see|since|todo|fixme|internal|api)/i', $trimmed);
    }
}
