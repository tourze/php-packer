<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

use PhpPacker\Storage\SqliteStorage;
use PhpParser\Node;
use Psr\Log\LoggerInterface;

class AstMerger
{
    private LoggerInterface $logger;

    private VendorFileProcessor $vendorProcessor;

    private ProjectFileProcessor $projectProcessor;

    private AstOptimizer $optimizer;

    /**
     * @phpstan-ignore-next-line constructor.unusedParameter
     */
    public function __construct(SqliteStorage $storage, LoggerInterface $logger)
    {
        $this->logger = $logger;

        // 创建助手类实例
        $deduplicator = new NodeDeduplicator($logger);
        $this->vendorProcessor = new VendorFileProcessor($logger);
        $this->projectProcessor = new ProjectFileProcessor($logger, $deduplicator);
        $this->optimizer = new AstOptimizer($logger);
    }

    /**
     * 合并多个文件的 AST
     *
     * @param array<int, array{id: int, path: string, content: string, is_vendor: bool, skip_ast: bool}> $files 文件数据数组，每个元素包含 id, path, content 等
     *
     * @return array<int, Node> 合并后的 AST
     */
    public function mergeFiles(array $files): array
    {
        $this->logger->info('Starting AST merge', ['files' => count($files)]);

        // 分离 vendor 文件和项目文件
        [$vendorFiles, $projectFiles] = $this->separateFiles($files);

        // 构建合并后的 AST 结构
        $mergedAst = [];

        // 1. 首先处理 vendor 文件（作为原始内容包含）
        if (count($vendorFiles) > 0) {
            $vendorNodes = $this->vendorProcessor->createVendorNodes($vendorFiles);
            $mergedAst = array_merge($mergedAst, $vendorNodes);
        }

        // 2. 处理项目文件的 AST
        $projectNodes = $this->projectProcessor->mergeProjectFiles($projectFiles);
        $mergedAst = array_merge($mergedAst, $projectNodes);

        $this->logger->info('AST merge completed', [
            'vendor_files' => count($vendorFiles),
            'project_files' => count($projectFiles),
            'total_nodes' => count($mergedAst),
        ]);

        return $mergedAst;
    }

    /**
     * @param array<int, array{id: int, path: string, content: string, is_vendor: bool, skip_ast: bool}> $files
     * @return array{0: array<int, array{id: int, path: string, content: string, is_vendor: bool, skip_ast: bool}>, 1: array<int, array{id: int, path: string, content: string, is_vendor: bool, skip_ast: bool}>}
     */
    private function separateFiles(array $files): array
    {
        $vendorFiles = [];
        $projectFiles = [];

        foreach ($files as $file) {
            if ($file['is_vendor'] || $file['skip_ast']) {
                $vendorFiles[] = $file;
            } else {
                $projectFiles[] = $file;
            }
        }

        return [$vendorFiles, $projectFiles];
    }

    /**
     * 优化合并后的 AST
     * @param array<int, Node> $ast
     * @return array<int, Node>
     */
    public function optimizeAst(array $ast): array
    {
        return $this->optimizer->optimizeAst($ast);
    }
}
