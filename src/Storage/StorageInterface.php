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
     * Add a symbol (class, interface, trait, function) to storage.
     *
     * @param int $fileId The file ID
     * @param string $symbolType Type of symbol (class, interface, trait, function)
     * @param string $symbolName Name of the symbol
     * @param string $fqn Fully qualified name
     * @param string|null $namespace Namespace
     * @param string|null $visibility Visibility (public, private, protected)
     * @param bool $isAbstract Whether the symbol is abstract
     * @param bool $isFinal Whether the symbol is final
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
     * Add a dependency between files.
     *
     * @param int $sourceFileId Source file ID
     * @param int|null $targetFileId Target file ID (null if unresolved)
     * @param string $dependencyType Type of dependency
     * @param string|null $targetSymbol Target symbol name
     * @param int|null $lineNumber Line number where dependency occurs
     * @param bool $isConditional Whether dependency is conditional
     * @param string|null $context Additional context
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
     * Add an autoload rule.
     */
    public function addAutoloadRule(string $type, string $path, ?string $prefix = null, int $priority = 100): void;

    /**
     * Get file by path.
     */
    public function getFileByPath(string $path): ?array;

    /**
     * Get file by ID.
     */
    public function getFileById(int $id): ?array;

    /**
     * Find file by symbol name.
     */
    public function findFileBySymbol(string $fqn): ?array;

    /**
     * Get all autoload rules.
     */
    public function getAutoloadRules(): array;

    /**
     * Get storage statistics.
     */
    public function getStatistics(): array;

    /**
     * Store AST data for a file.
     */
    public function storeAst(int $fileId, array $ast): void;

    /**
     * Get AST nodes by file ID.
     */
    public function getAstNodesByFileId(int $fileId): array;

    /**
     * Get AST nodes by fully qualified class name.
     */
    public function getAstNodesByFqcn(string $fqcn): array;

    /**
     * Get all required files for entry point.
     */
    public function getAllRequiredFiles(int $entryFileId): array;

    /**
     * Get unresolved dependencies.
     */
    public function getUnresolvedDependencies(): array;

    /**
     * Get dependencies by file.
     */
    public function getDependenciesByFile(int $fileId): array;

    /**
     * Get all dependencies.
     */
    public function getAllDependencies(): array;

    /**
     * Resolve a dependency by setting target file ID.
     */
    public function resolveDependency(int $dependencyId, int $targetFileId): void;

    /**
     * Mark file as analyzed.
     */
    public function markFileAnalyzed(int $fileId): void;

    /**
     * Get the PDO instance for direct database access.
     */
    public function getPdo(): \PDO;
}
