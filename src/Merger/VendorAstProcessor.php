<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

use PhpPacker\Visitor\FqcnTransformVisitor;
use PhpParser\Comment;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

class VendorAstProcessor
{
    private Parser $parser;

    public function __construct()
    {
        $factory = new ParserFactory();
        $this->parser = $factory->createForNewestSupportedVersion();
    }

    /**
     * @return array<Node>|null
     */
    public function parseFile(string $content): ?array
    {
        try {
            return $this->parser->parse($content);
        } catch (Error $e) {
            return null;
        }
    }

    /**
     * @param array<Node> $ast
     * @return array<Node>
     */
    public function transformAst(array $ast): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);

        $fqcnTraverser = new NodeTraverser();
        $fqcnTraverser->addVisitor(new FqcnTransformVisitor());

        return $fqcnTraverser->traverse($ast);
    }

    /**
     * @param array<Node> $ast
     * @return array<Node>
     */
    public function filterNodes(array $ast): array
    {
        $filteredAst = [];
        foreach ($ast as $node) {
            if (!($node instanceof Node\Stmt\Declare_)) {
                $filteredAst[] = $node;
            }
        }

        return $filteredAst;
    }

    /**
     * @param array<Node> $nodes
     */
    public function addFileComment(array $nodes, string $path): void
    {
        if (count($nodes) > 0) {
            $comment = new Comment('// Vendor file: ' . $path);
            $nodes[0]->setAttribute('comments', [$comment]);
        }
    }

    /**
     * @param array<Node> $ast
     */
    public function extractNamespace(array $ast): string
    {
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                return null !== $node->name ? $node->name->toString() : '';
            }
        }

        return '';
    }

    /**
     * @param array<Node> $ast
     * @return array<string>
     */
    public function extractClasses(array $ast): array
    {
        // First ensure namespacedName is initialized
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);

        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string> */
            private array $classes = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Class_) {
                    if (isset($node->namespacedName)) {
                        $this->classes[] = $node->namespacedName->toString();
                    } elseif (null !== $node->name) {
                        $this->classes[] = $node->name->toString();
                    }
                }

                return null;
            }

            /**
             * @return array<string>
             */
            public function getClasses(): array
            {
                return $this->classes;
            }
        };

        $classTraverser = new NodeTraverser();
        $classTraverser->addVisitor($visitor);
        $classTraverser->traverse($ast);

        return $visitor->getClasses();
    }

    /**
     * @param array<Node> $ast
     * @return array<Node>
     */
    public function stripComments(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            public function enterNode(Node $node): Node
            {
                $node->setAttribute('comments', []);

                return $node;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);

        return $traverser->traverse($ast);
    }
}
