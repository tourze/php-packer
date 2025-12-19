<?php

declare(strict_types=1);

namespace PhpPacker\Storage;

use PhpParser\Node;

/**
 * PHP打包器分析数据的存储接口。
 *
 * 此接口定义了存储实现的契约，用于处理文件、符号、依赖关系
 * 和AST数据在PHP打包过程中的存储操作。
 */
interface StorageInterface
{
    /**
     * 添加文件到存储。
     */
    public function addFile(string $path, string $content, ?string $fileType = null, ?bool $isEntry = null, ?bool $shouldSkipAst = null, ?string $className = null): int;

    /**
     * 添加符号（类、接口、trait、函数）到存储。
     *
     * @param int $fileId 文件ID
     * @param string $symbolType 符号类型（class, interface, trait, function）
     * @param string $symbolName 符号名称
     * @param string $fqn 完全限定名
     * @param string|null $namespace 命名空间
     * @param string|null $visibility 可见性（public, private, protected）
     * @param bool $isAbstract 是否为抽象符号
     * @param bool $isFinal 是否为final符号
     */
    public function addSymbol(
        int $fileId,
        string $symbolType,
        string $symbolName,
        string $fqn,
        ?string $namespace = null,
        ?string $visibility = null,
        bool $isAbstract = false,
        bool $isFinal = false
    ): void;

    /**
     * 添加文件之间的依赖关系。
     *
     * @param int $sourceFileId 源文件ID
     * @param int|null $targetFileId 目标文件ID（未解析时为null）
     * @param string $dependencyType 依赖类型
     * @param string|null $targetSymbol 目标符号名称
     * @param int|null $lineNumber 依赖发生的行号
     * @param bool $isConditional 是否为条件依赖
     * @param string|null $context 附加上下文
     */
    public function addDependency(
        int $sourceFileId,
        ?int $targetFileId,
        string $dependencyType,
        ?string $targetSymbol = null,
        ?int $lineNumber = null,
        bool $isConditional = false,
        ?string $context = null
    ): void;

    /**
     * 添加自动加载规则。
     */
    public function addAutoloadRule(string $type, string $path, ?string $prefix = null, int $priority = 100): void;

    /**
     * 根据路径获取文件。
     */
    public function getFileByPath(string $path): ?array;

    /**
     * 根据ID获取文件。
     */
    public function getFileById(int $id): ?array;

    /**
     * 根据符号名查找文件。
     */
    public function findFileBySymbol(string $fqn): ?array;

    /**
     * 获取所有自动加载规则。
     */
    public function getAutoloadRules(): array;

    /**
     * 获取存储统计信息。
     */
    public function getStatistics(): array;

    /**
     * 存储文件的AST数据。
     */
    public function storeAst(int $fileId, array $ast): void;

    /**
     * 根据文件ID获取AST节点。
     */
    public function getAstNodesByFileId(int $fileId): array;

    /**
     * 根据完全限定类名获取AST节点。
     */
    public function getAstNodesByFqcn(string $fqcn): array;

    /**
     * 获取入口点所需的所有文件。
     */
    public function getAllRequiredFiles(int $entryFileId): array;

    /**
     * 获取未解析的依赖。
     */
    public function getUnresolvedDependencies(): array;

    /**
     * 根据文件获取依赖。
     */
    public function getDependenciesByFile(int $fileId): array;

    /**
     * 获取所有依赖。
     */
    public function getAllDependencies(): array;

    /**
     * 通过设置目标文件ID解析依赖。
     */
    public function resolveDependency(int $dependencyId, int $targetFileId): void;

    /**
     * 标记文件为已分析。
     */
    public function markFileAnalyzed(int $fileId): void;

    /**
     * 获取PDO实例用于直接数据库访问。
     */
    public function getPdo(): \PDO;
}
