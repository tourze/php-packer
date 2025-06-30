<?php

declare(strict_types=1);

namespace PhpPacker\Generator;

use PhpPacker\Exception\CodeGenerationException;
use PhpPacker\Merger\AstMerger;
use PhpPacker\Storage\SqliteStorage;
use PhpPacker\Visitor\FqcnTransformVisitor;
use PhpPacker\Visitor\RequireRemovalVisitor;
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
    private array $config;
    private Parser $parser;

    public function __construct(
        SqliteStorage $storage,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->printer = new PrettyPrinter\Standard();
        $this->merger = new AstMerger($storage, $logger);
        $this->logger = $logger;
        $this->config = $config;
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * 从文件列表生成打包后的代码
     *
     * @param array $files 文件列表
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
        if (empty($files)) {
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
        $mergedAst = $this->merger->mergeFiles($files);
        
        // 2. 优化 AST（可选）
        if ($this->config['optimization']['enabled'] ?? false) {
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
     * 生成引导代码
     */
    private function generateBootstrap(array $files, string $entryFile): array
    {
        $nodes = [];
        
        // 设置错误处理（默认不启用）
        if ($this->config['error_handler'] ?? false) {
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
                new Node\Param(new Node\Expr\Variable('severity')),
                new Node\Param(new Node\Expr\Variable('message')),
                new Node\Param(new Node\Expr\Variable('file')),
                new Node\Param(new Node\Expr\Variable('line')),
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
                            new Node\Stmt\Return_(new Node\Expr\ConstFetch(new Node\Name('false')))
                        ]
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
                )
            ]
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
     */
    private function buildFinalAst(array $bootstrap, array $mergedAst, string $entryFile, array $files): array
    {
        $ast = [];
        
        // PHP 开始标记
        $ast[] = new Node\Stmt\InlineHTML('');
        
        // declare(strict_types=1)
        $ast[] = new Node\Stmt\Declare_([
            new Node\Stmt\DeclareDeclare('strict_types', new Node\Scalar\LNumber(1))
        ]);
        
        // 重新组织命名空间，确保使用正确的语法
        $namespaceGroups = [];
        $globalNodes = [];
        
        foreach ($mergedAst as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                $nsName = $node->name !== null ? $node->name->toString() : '__global__';
                if (!isset($namespaceGroups[$nsName])) {
                    $namespaceGroups[$nsName] = [];
                }
                // 将该命名空间下的所有语句添加到组中
                if (!empty($node->stmts)) {
                    $namespaceGroups[$nsName] = array_merge($namespaceGroups[$nsName], $node->stmts);
                }
            } else {
                $globalNodes[] = $node;
            }
        }
        
        // 如果有多个命名空间，使用大括号语法
        if (count($namespaceGroups) > 1 || (!empty($namespaceGroups) && !empty($globalNodes))) {
            // 先处理非全局命名空间
            foreach ($namespaceGroups as $nsName => $stmts) {
                if ($nsName !== '__global__' && !empty($stmts)) {
                    $ast[] = new Node\Stmt\Namespace_(
                        new Node\Name(explode('\\', $nsName)),
                        $stmts
                    );
                }
            }
            
            // 处理全局命名空间或全局代码
            $globalStmts = array_merge(
                $globalNodes,
                isset($namespaceGroups['__global__']) ? $namespaceGroups['__global__'] : []
            );
            
            if (!empty($globalStmts)) {
                // 使用空命名空间表示全局命名空间
                $ast[] = new Node\Stmt\Namespace_(null, $globalStmts);
            }
        } elseif (count($namespaceGroups) === 1) {
            // 只有一个命名空间，使用声明式语法
            foreach ($namespaceGroups as $nsName => $stmts) {
                if ($nsName === '__global__') {
                    $ast = array_merge($ast, $stmts);
                } else {
                    $ast[] = new Node\Stmt\Namespace_(
                        new Node\Name(explode('\\', $nsName))
                    );
                    $ast = array_merge($ast, $stmts);
                }
            }
        } else {
            // 只有全局代码
            $ast = array_merge($ast, $globalNodes);
        }
        
        // 最后放执行代码（引导代码 + 入口代码）
        // 传递已合并的文件列表，以便过滤 require 语句
        $mergedPaths = [];
        foreach ($files as $file) {
            if (isset($file['path'])) {
                // 保存完整路径和 basename
                $mergedPaths[] = $file['path'];
                $mergedPaths[] = basename($file['path']);
            }
        }
        
        $this->logger->debug('Merged paths for filtering', ['paths' => $mergedPaths]);
        
        // 尝试从文件列表中找到入口文件
        $actualEntryFile = null;
        foreach ($files as $file) {
            if ($file['is_entry'] == 1) {
                $actualEntryFile = $file['path'];
                break;
            }
        }
        
        // 如果找不到，使用传入的 entryFile
        if (!$actualEntryFile) {
            $actualEntryFile = $entryFile;
        }
        
        $this->logger->debug('Using entry file for code extraction', [
            'actualEntryFile' => $actualEntryFile,
            'originalEntryFile' => $entryFile
        ]);
        
        $entryCode = $this->extractEntryCode($actualEntryFile, $mergedPaths);
        
        // 对入口代码应用 FQCN 转换
        if (!empty($entryCode)) {
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $entryCode = $traverser->traverse($entryCode);
            
            // 移除 use 语句（如果有的话）
            $fqcnTraverser = new NodeTraverser();
            $fqcnTraverser->addVisitor(new FqcnTransformVisitor());
            $entryCode = $fqcnTraverser->traverse($entryCode);
        }
        
        $this->logger->debug('Entry code count: ' . count($entryCode));
        
        // 合并执行代码（bootstrap + entry code）
        $executionCode = array_merge($bootstrap, $entryCode);
        
        // 将执行代码添加到适当的位置
        if (count($namespaceGroups) > 1 || (!empty($namespaceGroups) && !empty($globalNodes))) {
            // 找到全局命名空间节点
            $globalNamespaceFound = false;
            foreach ($ast as &$node) {
                if ($node instanceof Node\Stmt\Namespace_ && $node->name === null) {
                    // 添加到全局命名空间
                    $node->stmts = array_merge($node->stmts, $executionCode);
                    $globalNamespaceFound = true;
                    break;
                }
            }
            
            if (!$globalNamespaceFound) {
                // 如果没有全局命名空间，创建一个
                $ast[] = new Node\Stmt\Namespace_(null, $executionCode);
            }
        } else {
            // 单命名空间或无命名空间的情况，直接添加执行代码
            $ast = array_merge($ast, $executionCode);
        }
        
        return $ast;
    }

    /**
     * 提取入口文件的执行代码（去除 namespace、class 定义和 require 语句）
     */
    private function extractEntryCode(string $entryFile, array $mergedFiles = []): array
    {
        try {
            $this->logger->debug('Extracting entry code', [
                'entryFile' => $entryFile,
                'mergedFiles' => $mergedFiles
            ]);
            
            if (!file_exists($entryFile)) {
                $this->logger->warning('Entry file does not exist', ['file' => $entryFile]);
                return [];
            }
            
            $content = file_get_contents($entryFile);
            if ($content === false) {
                $this->logger->warning('Failed to read entry file', ['file' => $entryFile]);
                return [];
            }
            
            $parser = new \PhpParser\ParserFactory();
            $ast = $parser->createForNewestSupportedVersion()->parse($content);
            if ($ast === null) {
                return [];
            }
            
            $this->logger->debug('Parsed AST nodes', [
                'count' => count($ast),
                'types' => array_map(function($node) { return $node->getType(); }, $ast)
            ]);
            
            // 使用 NameResolver 解析名称为 FQCN
            $traverser = new \PhpParser\NodeTraverser();
            $traverser->addVisitor(new \PhpParser\NodeVisitor\NameResolver());
            $ast = $traverser->traverse($ast);
            
            // 使用另一个 traverser 来移除已合并文件的 require 语句
            // 对于入口文件，我们应该移除所有的 require/include 语句，因为所有依赖都已经被合并
            $requireRemover = new \PhpParser\NodeTraverser();
            $requireRemover->addVisitor(new RequireRemovalVisitor($mergedFiles, true)); // true 表示移除所有 require
            $ast = $requireRemover->traverse($ast);
            
            $executionNodes = [];
            
            // 递归函数来提取执行节点
            $extractExecution = function($nodes) use (&$extractExecution, $mergedFiles) {
                $result = [];
                foreach ($nodes as $node) {
                    // 处理命名空间内的语句
                    if ($node instanceof Node\Stmt\Namespace_) {
                        if (!empty($node->stmts)) {
                            $result = array_merge($result, $extractExecution($node->stmts));
                        }
                        continue;
                    }
                    
                    // 跳过类定义、接口定义、trait定义、函数定义、declare语句
                    if ($node instanceof Node\Stmt\Class_ ||
                        $node instanceof Node\Stmt\Interface_ ||
                        $node instanceof Node\Stmt\Trait_ ||
                        $node instanceof Node\Stmt\Function_ ||
                        $node instanceof Node\Stmt\Declare_) {
                        continue;
                    }
                    
                    // 处理 require/include 语句
                    if ($node instanceof Node\Stmt\Expression &&
                        $node->expr instanceof Node\Expr\Include_) {
                        // 检查是否是已合并的文件
                        if ($node->expr->expr instanceof Node\Scalar\String_) {
                            $requiredFile = $node->expr->expr->value;
                            if (in_array($requiredFile, $mergedFiles)) {
                                // 跳过已合并的文件
                                continue;
                            }
                        }
                        // 保留其他 include 语句（动态 include 等）
                        $result[] = $node;
                        continue;
                    }
                    
                    // 跳过 use 语句
                    if ($node instanceof Node\Stmt\Use_ ||
                        $node instanceof Node\Stmt\GroupUse) {
                        continue;
                    }
                    
                    // 其他语句认为是执行代码
                    $result[] = $node;
                }
                return $result;
            };
            
            $executionNodes = $extractExecution($ast);
            
            $this->logger->debug('Extracted entry code nodes', [
                'count' => count($executionNodes),
                'types' => array_map(function($node) { return $node->getType(); }, $executionNodes),
                'nodeDetails' => array_map(function($node) {
                    if ($node instanceof Node\Stmt\Expression && $node->expr instanceof Node\Expr\Assign) {
                        return 'Assignment: $' . ($node->expr->var instanceof Node\Expr\Variable ? $node->expr->var->name : 'unknown');
                    } elseif ($node instanceof Node\Stmt\Expression && $node->expr instanceof Node\Expr\MethodCall) {
                        return 'Method call';
                    }
                    return $node->getType();
                }, $executionNodes),
                'code' => array_map(function($node) {
                    $printer = new PrettyPrinter\Standard();
                    return $printer->prettyPrint([$node]);
                }, $executionNodes)
            ]);
            
            return $executionNodes;
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract entry code', [
                'file' => $entryFile,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * 将 AST 转换为 PHP 代码
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
        if (!empty($ast)) {
            $parts[] = $this->printer->prettyPrint(array_values($ast));
        }
        
        $code = implode("\n", $parts);
        
        // 应用优化
        if ($this->config['optimization']['remove_comments'] ?? false) {
            $code = $this->removeComments($code);
        }
        
        if ($this->config['optimization']['minimize_whitespace'] ?? false) {
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
            if ($ast === null) {
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
        $code = preg_replace("/\n\s*\n\s*\n/", "\n\n", $code);
        
        // 移除行尾空白
        $code = preg_replace("/[ \t]+$/m", "", $code);
        
        return $code;
    }

    /**
     * 写入输出文件
     */
    private function writeOutput(string $outputPath, string $content): void
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        if (file_put_contents($outputPath, $content) === false) {
            throw new CodeGenerationException("Failed to write output file: $outputPath");
        }
        
        // 设置可执行权限
        chmod($outputPath, 0755);
        
        $this->logger->info('Output file written', [
            'path' => $outputPath,
            'size' => filesize($outputPath),
        ]);
    }
}
