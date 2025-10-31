<?php

declare(strict_types=1);

namespace PhpPacker\Generator;

use PhpPacker\Visitor\RequireRemovalVisitor;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use Psr\Log\LoggerInterface;

class EntryCodeExtractor
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param string $entryFile
     * @param array<int, string> $mergedFiles
     * @return array<int, Node>
     */
    public function extractEntryCode(string $entryFile, array $mergedFiles = []): array
    {
        try {
            $this->logger->debug('Extracting entry code', [
                'entryFile' => $entryFile,
                'mergedFiles' => $mergedFiles,
            ]);

            $content = $this->readEntryFile($entryFile);
            if ('' === $content) {
                return [];
            }

            $ast = $this->parseEntryFile($content);
            if ([] === $ast) {
                return [];
            }

            $ast = $this->processEntryAst($ast, $mergedFiles);
            $executionNodes = $this->extractExecutionNodes($ast, $mergedFiles);
            $this->logExtractedNodes($executionNodes);

            return $executionNodes;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract entry code', [
                'file' => $entryFile,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function readEntryFile(string $entryFile): string
    {
        if (!file_exists($entryFile)) {
            $this->logger->warning('Entry file does not exist', ['file' => $entryFile]);

            return '';
        }

        $content = file_get_contents($entryFile);
        if (false === $content) {
            $this->logger->warning('Failed to read entry file', ['file' => $entryFile]);

            return '';
        }

        return $content;
    }

    /** @return array<int, Node> */
    private function parseEntryFile(string $content): array
    {
        $parser = new ParserFactory();
        $ast = $parser->createForNewestSupportedVersion()->parse($content);
        if (null === $ast) {
            return [];
        }

        $this->logger->debug('Parsed AST nodes', [
            'count' => count($ast),
            'types' => array_map(function (Node $node): string { return $node->getType(); }, $ast),
        ]);

        return $ast;
    }

    /**
     * @param array<int, Node> $ast
     * @param array<int, string> $mergedFiles
     * @return array<int, Node>
     */
    private function processEntryAst(array $ast, array $mergedFiles): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);

        $requireRemover = new NodeTraverser();
        $requireRemover->addVisitor(new RequireRemovalVisitor($mergedFiles, true));

        return $requireRemover->traverse($ast);
    }

    /**
     * @param array<int, Node> $ast
     * @param array<int, string> $mergedFiles
     * @return array<int, Node>
     */
    private function extractExecutionNodes(array $ast, array $mergedFiles): array
    {
        $extractor = new ExecutionNodeExtractor($mergedFiles);

        return $extractor->extract($ast);
    }

    /**
     * @param array<int, Node> $executionNodes
     */
    private function logExtractedNodes(array $executionNodes): void
    {
        $this->logger->debug('Extracted entry code nodes', [
            'count' => count($executionNodes),
            'types' => array_map(function (Node $node): string { return $node->getType(); }, $executionNodes),
            'nodeDetails' => array_map(function (Node $node): string {
                if ($node instanceof Node\Stmt\Expression && $node->expr instanceof Node\Expr\Assign) {
                    $varName = 'unknown';
                    if ($node->expr->var instanceof Node\Expr\Variable && is_string($node->expr->var->name)) {
                        $varName = $node->expr->var->name;
                    }

                    return 'Assignment: $' . $varName;
                }
                if ($node instanceof Node\Stmt\Expression && $node->expr instanceof Node\Expr\MethodCall) {
                    return 'Method call';
                }

                return $node->getType();
            }, $executionNodes),
            'code' => array_map(function (Node $node): string {
                $printer = new PrettyPrinter\Standard();

                return $printer->prettyPrint([$node]);
            }, $executionNodes),
        ]);
    }
}
