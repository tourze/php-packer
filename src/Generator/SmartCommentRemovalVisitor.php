<?php

namespace PhpPacker\Generator;

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
            if ($docComment !== null) {
                // 检查是否有必要保留的注释
                $comment = $docComment->getText();
                $necessaryComment = $this->filterNecessaryComment($comment, $node);

                if (!empty($necessaryComment)) {
                    // 创建新的注释节点
                    $newComment = new \PhpParser\Comment\Doc($necessaryComment);
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
        if (!($node instanceof Node\Stmt\Function_) && !($node instanceof Node\Stmt\ClassMethod)) {
            return '';
        }

        $lines = explode("\n", $comment);
        $filteredLines = [];
        $hasDescription = false;

        // 检查函数/方法的参数和返回类型
        $params = $node->params;
        $returnType = $node->returnType;

        // 创建参数类型映射
        $paramTypes = [];
        foreach ($params as $param) {
            if ($param->type !== null) {
                $paramTypes[$param->var->name] = true;
            }
        }

        foreach ($lines as $line) {
            $trimmed = trim($line, " \t*");

            // 保留描述行（不是 @param 或 @return）
            if (!empty($trimmed) && !str_starts_with($trimmed, '@')) {
                if ($trimmed !== '/') {
                    $hasDescription = true;
                    $filteredLines[] = $line;
                }
            } // 检查 @param 注解
            elseif (preg_match('/@param\s+(\S+)\s+\$(\w+)/', $trimmed, $matches)) {
                $paramName = $matches[2];
                // 如果参数没有类型声明，保留注解
                if (!isset($paramTypes[$paramName])) {
                    $filteredLines[] = $line;
                }
            } // 检查 @return 注解
            elseif (str_starts_with($trimmed, '@return') && $returnType === null) {
                // 如果没有返回类型声明，保留注解
                $filteredLines[] = $line;
            } // 保留其他重要注解（如 @throws, @deprecated 等）
            elseif (preg_match('/@(throws|deprecated|see|since|todo|fixme|internal|api)/i', $trimmed)) {
                $filteredLines[] = $line;
            }
        }

        // 如果没有任何内容需要保留，返回空字符串
        if (empty($filteredLines)) {
            return '';
        }

        // 重建注释
        return implode("\n", $filteredLines);
    }
}