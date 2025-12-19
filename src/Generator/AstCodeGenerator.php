<?php

declare(strict_types=1);

namespace PhpPacker\Generator;

use PhpPacker\Exception\CodeGenerationException;
use PhpPacker\Merger\AstMerger;
use PhpPacker\Visitor\FqcnTransformVisitor;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use Psr\Log\LoggerInterface;

class AstCodeGenerator
{
    private PrettyPrinter\Standard $printer;

    private AstMerger $merger;

    private LoggerInterface $logger;

    /** @var array<string, mixed> */
    private array $config;

    private Parser $parser;

    private AstBuilder $astBuilder;

    private EntryCodeExtractor $entryExtractor;

    /**
     * @param LoggerInterface $logger
     * @param array<string, mixed> $config
     */
    public function __construct(
        LoggerInterface $logger,
        array $config = [],
    ) {
        $this->printer = new PrettyPrinter\Standard();
        $this->merger = new AstMerger($logger);
        $this->logger = $logger;
        $this->config = $config;
        $factory = new ParserFactory();
        $this->parser = $factory->createForNewestSupportedVersion();
        $this->astBuilder = new AstBuilder();
        $this->entryExtractor = new EntryCodeExtractor($logger);
    }

    /**
     * 从文件列表生成打包后的代码
     *
     * @param array<int, array{path: string, content: string}> $files 文件列表
     * @param string $entryFile 入口文件路径
     * @param string $outputPath 输出文件路径
     */
    public function generate(array $files, string $entryFile, string $outputPath): void
    {
        $this->logger->info('Starting code generation', [
            'files' => count($files),
            'entry' => $entryFile,
            'output' => $outputPath,
        ]);

        // 验证输入
        if ([] === $files) {
            throw new CodeGenerationException('No files provided for code generation');
        }

        // 检查入口文件是否存在
        $entryFound = false;
        foreach ($files as $file) {
            if (str_ends_with($entryFile, $file['path']) || $file['path'] === basename($entryFile)) {
                $entryFound = true;
                break;
            }
        }

        if (!$entryFound) {
            throw new CodeGenerationException('Entry file not found in provided files: ' . $entryFile);
        }

        // 1. 合并所有文件的 AST
        $adaptedFiles = $this->adaptFilesForMerger($files);
        $mergedAst = $this->merger->mergeFiles($adaptedFiles);

        // 2. 优化 AST（可选）
        if (true === ($this->config['optimization']['enabled'] ?? false)) {
            $mergedAst = $this->merger->optimizeAst($mergedAst);
        }

        // 3. 生成引导代码
        $bootstrap = $this->generateBootstrap($files, $entryFile);

        // 4. 生成最终的 AST
        $finalAst = $this->buildFinalAst($bootstrap, $mergedAst, $entryFile, $files);

        // 5. 将 AST 转换为代码
        $code = $this->generateCode($finalAst);

        // 6. 写入文件
        $this->writeOutput($outputPath, $code);

        $this->logger->info('Code generation completed', [
            'size' => strlen($code),
            'output' => $outputPath,
        ]);
    }

    /**
     * 适配文件格式以供合并器使用
     * @param array<int, array{path: string, content: string}> $files
     * @return array<int, array{id: int, path: string, content: string, is_vendor: bool, skip_ast: bool}>
     */
    private function adaptFilesForMerger(array $files): array
    {
        $adaptedFiles = [];
        foreach ($files as $index => $file) {
            $adaptedFiles[] = [
                'id' => $index + 1,
                'path' => $file['path'],
                'content' => $file['content'],
                'is_vendor' => str_contains($file['path'], 'vendor/'),
                'skip_ast' => false,
            ];
        }

        return $adaptedFiles;
    }

    /**
     * 生成引导代码
     *
     * @param array<int, array{path: string, content: string}> $files
     * @param string $entryFile
     * @return array<int, Node>
     */
    private function generateBootstrap(array $files, string $entryFile): array
    {
        $nodes = [];

        // 设置错误处理（默认不启用）
        if (true === ($this->config['error_handler'] ?? false)) {
            $nodes[] = $this->createErrorHandler();
        }

        // 不再需要 __packed_info 和 vendor autoloader，因为所有代码都已经通过 AST 合并

        return $nodes;
    }

    /**
     * 创建错误处理器
     */
    private function createErrorHandler(): Node\Stmt
    {
        // 创建错误处理器函数
        $errorHandlerFunc = new Node\Expr\Closure([
            'params' => [
                new Node\Param(new Node\Expr\Variable('severity'), type: new Node\Identifier('int')),
                new Node\Param(new Node\Expr\Variable('message'), type: new Node\Identifier('string')),
                new Node\Param(new Node\Expr\Variable('file'), type: new Node\Identifier('string')),
                new Node\Param(new Node\Expr\Variable('line'), type: new Node\Identifier('int')),
            ],
            'stmts' => [
                // if (!(error_reporting() & $severity)) { return false; }
                new Node\Stmt\If_(
                    new Node\Expr\BooleanNot(
                        new Node\Expr\BinaryOp\BitwiseAnd(
                            new Node\Expr\FuncCall(new Node\Name('error_reporting')),
                            new Node\Expr\Variable('severity')
                        )
                    ),
                    [
                        'stmts' => [
                            new Node\Stmt\Return_(new Node\Expr\ConstFetch(new Node\Name('false'))),
                        ],
                    ]
                ),
                // throw new \ErrorException($message, 0, $severity, $file, $line);
                new Node\Stmt\Expression(
                    new Node\Expr\Throw_(
                        new Node\Expr\New_(
                            new Node\Name\FullyQualified('ErrorException'),
                            [
                                new Node\Arg(new Node\Expr\Variable('message')),
                                new Node\Arg(new Node\Scalar\LNumber(0)),
                                new Node\Arg(new Node\Expr\Variable('severity')),
                                new Node\Arg(new Node\Expr\Variable('file')),
                                new Node\Arg(new Node\Expr\Variable('line')),
                            ]
                        )
                    )
                ),
            ],
        ]);

        // set_error_handler($errorHandlerFunc)
        return new Node\Stmt\Expression(
            new Node\Expr\FuncCall(
                new Node\Name('set_error_handler'),
                [new Node\Arg($errorHandlerFunc)]
            )
        );
    }

    /**
     * 构建最终的 AST
     *
     * @param array<int, Node> $bootstrap
     * @param array<int, Node> $mergedAst
     * @param string $entryFile
     * @param array<int, array{path: string, content: string}> $files
     * @return array<int, Node>
     */
    private function buildFinalAst(array $bootstrap, array $mergedAst, string $entryFile, array $files): array
    {
        $ast = $this->astBuilder->createAstHeader();
        $namespaceGroups = $this->astBuilder->organizeNamespaces($mergedAst);
        $ast = $this->astBuilder->buildNamespaceStructure($ast, $namespaceGroups['groups'], $namespaceGroups['global']);
        $executionCode = $this->buildExecutionCode($bootstrap, $entryFile, $files);

        return $this->astBuilder->addExecutionCodeToAst($ast, $executionCode, $namespaceGroups['groups'], $namespaceGroups['global']);
    }

    /**
     * @param array<int, Node> $bootstrap
     * @param string $entryFile
     * @param array<int, array{path: string, content: string}> $files
     * @return array<int, Node\Stmt>
     */
    private function buildExecutionCode(array $bootstrap, string $entryFile, array $files): array
    {
        $mergedPaths = $this->extractMergedPaths($files);
        $this->logger->debug('Merged paths for filtering', ['paths' => $mergedPaths]);

        $actualEntryFile = $this->findActualEntryFile($files, $entryFile);
        $entryCode = $this->entryExtractor->extractEntryCode($actualEntryFile, $mergedPaths);
        $entryCode = $this->transformEntryCode($entryCode);

        $this->logger->debug('Entry code count: ' . count($entryCode));

        $result = array_merge($bootstrap, $entryCode);

        // 过滤出只有 Stmt 类型的节点
        return array_filter($result, static function ($node): bool {
            return $node instanceof Node\Stmt;
        });
    }

    /**
     * @param array<int, array{path: string, content: string}> $files
     * @return array<int, string>
     */
    private function extractMergedPaths(array $files): array
    {
        $mergedPaths = [];
        foreach ($files as $file) {
            if (isset($file['path'])) {
                $mergedPaths[] = $file['path'];
                $mergedPaths[] = basename($file['path']);
            }
        }

        return $mergedPaths;
    }

    /**
     * @param array<int, array{path: string, content: string, is_entry?: int}> $files
     * @param string $entryFile
     * @return string
     */
    private function findActualEntryFile(array $files, string $entryFile): string
    {
        foreach ($files as $file) {
            if (isset($file['is_entry']) && 1 === $file['is_entry']) {
                $actualEntryFile = $file['path'];
                $this->logger->debug('Using entry file for code extraction', [
                    'actualEntryFile' => $actualEntryFile,
                    'originalEntryFile' => $entryFile,
                ]);

                return $actualEntryFile;
            }
        }

        return $entryFile;
    }

    /**
     * @param array<int, Node> $entryCode
     * @return array<int, Node>
     */
    private function transformEntryCode(array $entryCode): array
    {
        if ([] === $entryCode) {
            return $entryCode;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $entryCode = $traverser->traverse($entryCode);

        $fqcnTraverser = new NodeTraverser();
        $fqcnTraverser->addVisitor(new FqcnTransformVisitor());

        return $fqcnTraverser->traverse($entryCode);
    }

    /**
     * 将 AST 转换为 PHP 代码
     *
     * @param array<int, Node> $ast
     * @return string
     */
    private function generateCode(array $ast): string
    {
        // 分离出第一个节点（应该是 InlineHTML）
        $firstNode = array_shift($ast);

        // 生成代码
        $parts = [];

        // PHP 开始标记
        $parts[] = "<?php\n";

        // declare 语句必须紧跟在 PHP 开始标记后
        $declareFound = false;
        foreach ($ast as $i => $node) {
            if ($node instanceof Node\Stmt\Declare_) {
                $parts[] = $this->printer->prettyPrint([$node]) . "\n";
                unset($ast[$i]);
                $declareFound = true;
                break;
            }
        }

        // 其余代码
        if ([] !== $ast) {
            $parts[] = $this->printer->prettyPrint(array_values($ast));
        }

        $code = implode("\n", $parts);

        // 应用优化
        if (true === ($this->config['optimization']['remove_comments'] ?? false)) {
            $code = $this->removeComments($code);
        }

        if (true === ($this->config['optimization']['minimize_whitespace'] ?? false)) {
            $code = $this->minimizeWhitespace($code);
        }

        return $code;
    }

    /**
     * 移除注释
     */
    private function removeComments(string $code): string
    {
        // 首先解析 AST 以获取类型信息
        try {
            $ast = $this->parser->parse($code);
            if (null === $ast) {
                // 如果解析失败，使用简单的注释移除
                return $this->simpleRemoveComments($code);
            }

            // 使用智能注释移除访问器
            $traverser = new NodeTraverser();
            $visitor = new SmartCommentRemovalVisitor();
            $traverser->addVisitor($visitor);
            $ast = $traverser->traverse($ast);

            // 生成代码
            return "<?php\n" . $this->printer->prettyPrint($ast);
        } catch (\Exception $e) {
            // 如果出错，回退到简单的注释移除
            return $this->simpleRemoveComments($code);
        }
    }

    /**
     * 简单的注释移除（备用方法）
     */
    private function simpleRemoveComments(string $code): string
    {
        $tokens = token_get_all($code);
        $result = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                switch ($token[0]) {
                    case T_COMMENT:
                    case T_DOC_COMMENT:
                        // 移除所有注释，包括单行注释、多行注释和文档注释
                        // 但保留换行符以维持行号
                        $lines = substr_count($token[1], "\n");
                        $result .= str_repeat("\n", $lines);
                        break;
                    default:
                        $result .= $token[1];
                }
            } else {
                $result .= $token;
            }
        }

        return $result;
    }

    /**
     * 最小化空白
     */
    private function minimizeWhitespace(string $code): string
    {
        // 移除多余的空行
        $code = preg_replace("/\n\\s*\n\\s*\n/", "\n\n", $code);
        if (null === $code) {
            return '';
        }

        // 移除行尾空白
        $result = preg_replace("/[ \t]+$/m", '', $code);

        return null === $result ? $code : $result;
    }

    /**
     * 写入输出文件
     */
    private function writeOutput(string $outputPath, string $content): void
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        if (false === file_put_contents($outputPath, $content)) {
            throw new CodeGenerationException("Failed to write output file: {$outputPath}");
        }

        // 设置可执行权限
        chmod($outputPath, 0o755);

        $this->logger->info('Output file written', [
            'path' => $outputPath,
            'size' => filesize($outputPath),
        ]);
    }
}
