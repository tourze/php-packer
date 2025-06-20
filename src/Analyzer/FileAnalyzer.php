<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer;

use PhpPacker\Storage\SqliteStorage;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

class FileAnalyzer
{
    private Parser $parser;
    private SqliteStorage $storage;
    private LoggerInterface $logger;
    private string $rootPath;

    public function __construct(SqliteStorage $storage, LoggerInterface $logger, string $rootPath)
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->storage = $storage;
        $this->logger = $logger;
        $this->rootPath = rtrim($rootPath, '/');
    }

    public function analyzeFile(string $filePath): void
    {
        $this->logger->info('Analyzing file', ['file' => $filePath]);

        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: $filePath");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: $filePath");
        }

        try {
            $ast = $this->parser->parse($content);
            if ($ast === null) {
                $this->logger->warning('Empty AST for file', ['file' => $filePath]);
                return;
            }

            $fileType = $this->detectFileType($ast);
            $namespace = $this->extractNamespace($ast);
            
            $fileId = $this->storage->addFile(
                $this->getRelativePath($filePath),
                $content,
                $fileType,
                $namespace
            );

            $visitor = new AnalysisVisitor($this->storage, $fileId, $namespace, $filePath);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            $this->logger->info('File analyzed successfully', [
                'file' => $filePath,
                'symbols' => $visitor->getSymbolCount(),
                'dependencies' => $visitor->getDependencyCount(),
            ]);
        } catch (Error $e) {
            $this->logger->error('Parse error in file', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Parse error in $filePath: " . $e->getMessage(), 0, $e);
        }
    }

    private function detectFileType(array $ast): string
    {
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Class_) {
                return 'class';
            } elseif ($node instanceof Node\Stmt\Interface_) {
                return 'interface';
            } elseif ($node instanceof Node\Stmt\Trait_) {
                return 'trait';
            }
        }
        return 'script';
    }

    private function extractNamespace(array $ast): ?string
    {
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                return $node->name ? $node->name->toString() : null;
            }
        }
        return null;
    }

    private function getRelativePath(string $path): string
    {
        $path = realpath($path);
        if (strpos($path, $this->rootPath) === 0) {
            return substr($path, strlen($this->rootPath) + 1);
        }
        return $path;
    }
}

class AnalysisVisitor extends NodeVisitorAbstract
{
    private SqliteStorage $storage;
    private int $fileId;
    private ?string $currentNamespace;
    private string $filePath;
    private array $useStatements = [];
    private int $symbolCount = 0;
    private int $dependencyCount = 0;

    public function __construct(SqliteStorage $storage, int $fileId, ?string $namespace, string $filePath)
    {
        $this->storage = $storage;
        $this->fileId = $fileId;
        $this->currentNamespace = $namespace;
        $this->filePath = $filePath;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name ? $node->name->toString() : null;
            $this->useStatements = [];
        } elseif ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $alias = $use->alias ? $use->alias->toString() : $use->name->getLast();
                $this->useStatements[$alias] = $use->name->toString();
            }
        } elseif ($node instanceof Node\Stmt\Class_) {
            $this->processClass($node);
        } elseif ($node instanceof Node\Stmt\Interface_) {
            $this->processInterface($node);
        } elseif ($node instanceof Node\Stmt\Trait_) {
            $this->processTrait($node);
        } elseif ($node instanceof Node\Stmt\Function_) {
            $this->processFunction($node);
        } elseif ($node instanceof Node\Expr\Include_) {
            $this->processInclude($node);
        } elseif ($node instanceof Node\Expr\New_) {
            $this->processNewInstance($node);
        } elseif ($node instanceof Node\Expr\StaticCall ||
                  $node instanceof Node\Expr\ClassConstFetch) {
            $this->processStaticReference($node);
        }

        return null;
    }

    private function processClass(Node\Stmt\Class_ $node): void
    {
        if (!$node->name) {
            return;
        }

        $className = $node->name->toString();
        $fqn = $this->buildFqn($className);
        
        $this->storage->addSymbol(
            $this->fileId,
            'class',
            $className,
            $fqn,
            $this->currentNamespace,
            $this->getVisibility($node)
        );
        $this->symbolCount++;

        if ($node->extends) {
            $this->addDependency('extends', $node->extends->toString(), $node->getLine());
        }

        foreach ($node->implements as $interface) {
            $this->addDependency('implements', $interface->toString(), $node->getLine());
        }

        foreach ($node->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $this->addDependency('use_trait', $trait->toString(), $traitUse->getLine());
            }
        }
    }

    private function buildFqn(string $name): string
    {
        return $this->currentNamespace ? $this->currentNamespace . '\\' . $name : $name;
    }

    private function getVisibility(Node\Stmt\Class_ $node): string
    {
        if ($node->isAbstract()) {
            return 'abstract';
        } elseif ($node->isFinal()) {
            return 'final';
        }
        return 'public';
    }

    private function addDependency(string $type, string $symbol, int $line): void
    {
        $resolvedSymbol = $this->resolveSymbol($symbol);

        $this->storage->addDependency([
            'source_file_id' => $this->fileId,
            'dependency_type' => $type,
            'target_symbol' => $resolvedSymbol,
            'line_number' => $line,
        ]);
        $this->dependencyCount++;
    }

    private function resolveSymbol(string $symbol): string
    {
        if ($symbol[0] === '\\') {
            return ltrim($symbol, '\\');
        }

        $parts = explode('\\', $symbol);
        $firstPart = $parts[0];

        if (isset($this->useStatements[$firstPart])) {
            $parts[0] = $this->useStatements[$firstPart];
            return implode('\\', $parts);
        }

        if ($this->currentNamespace && !$this->isBuiltinClass($symbol)) {
            return $this->currentNamespace . '\\' . $symbol;
        }

        return $symbol;
    }

    private function isBuiltinClass(string $class): bool
    {
        $builtinClasses = [
            'Exception', 'ErrorException', 'Error', 'ParseError', 'TypeError',
            'ArgumentCountError', 'ArithmeticError', 'DivisionByZeroError',
            'Closure', 'Generator', 'DateTime', 'DateTimeImmutable', 'DateTimeZone',
            'DateInterval', 'DatePeriod', 'stdClass', 'ArrayObject', 'ArrayIterator',
        ];

        return in_array($class, $builtinClasses, true);
    }

    private function processInterface(Node\Stmt\Interface_ $node): void
    {
        $interfaceName = $node->name->toString();
        $fqn = $this->buildFqn($interfaceName);

        $this->storage->addSymbol(
            $this->fileId,
            'interface',
            $interfaceName,
            $fqn,
            $this->currentNamespace
        );
        $this->symbolCount++;

        foreach ($node->extends as $extend) {
            $this->addDependency('extends', $extend->toString(), $node->getLine());
        }
    }

    private function processTrait(Node\Stmt\Trait_ $node): void
    {
        $traitName = $node->name->toString();
        $fqn = $this->buildFqn($traitName);

        $this->storage->addSymbol(
            $this->fileId,
            'trait',
            $traitName,
            $fqn,
            $this->currentNamespace
        );
        $this->symbolCount++;
    }

    private function processFunction(Node\Stmt\Function_ $node): void
    {
        $functionName = $node->name->toString();
        $fqn = $this->currentNamespace ? $this->currentNamespace . '\\' . $functionName : $functionName;

        $this->storage->addSymbol(
            $this->fileId,
            'function',
            $functionName,
            $fqn,
            $this->currentNamespace
        );
        $this->symbolCount++;
    }

    private function processInclude(Node\Expr\Include_ $node): void
    {
        $type = match ($node->type) {
            Node\Expr\Include_::TYPE_INCLUDE => 'include',
            Node\Expr\Include_::TYPE_INCLUDE_ONCE => 'include_once',
            Node\Expr\Include_::TYPE_REQUIRE => 'require',
            Node\Expr\Include_::TYPE_REQUIRE_ONCE => 'require_once',
            default => 'include',
        };

        $context = $this->extractIncludeContext($node);
        $isConditional = $this->isConditionalInclude($node);

        $this->storage->addDependency([
            'source_file_id' => $this->fileId,
            'dependency_type' => $type,
            'line_number' => $node->getLine(),
            'is_conditional' => $isConditional,
            'context' => $context,
        ]);
        $this->dependencyCount++;
    }

    private function extractIncludeContext(Node\Expr\Include_ $node): ?string
    {
        if ($node->expr instanceof Node\Scalar\String_) {
            return $node->expr->value;
        } elseif ($node->expr instanceof Node\Expr\BinaryOp\Concat) {
            return 'dynamic';
        }
        return 'complex';
    }

    private function isConditionalInclude(Node $node): bool
    {
        $parent = $node->getAttribute('parent');
        while ($parent) {
            if ($parent instanceof Node\Stmt\If_ ||
                $parent instanceof Node\Stmt\ElseIf_ ||
                $parent instanceof Node\Stmt\Else_ ||
                $parent instanceof Node\Stmt\Switch_ ||
                $parent instanceof Node\Stmt\While_ ||
                $parent instanceof Node\Stmt\For_ ||
                $parent instanceof Node\Stmt\Foreach_) {
                return true;
            }
            $parent = $parent->getAttribute('parent');
        }
        return false;
    }

    private function processNewInstance(Node\Expr\New_ $node): void
    {
        if ($node->class instanceof Node\Name) {
            $className = $node->class->toString();
            $this->addDependency('use_class', $className, $node->getLine());
        }
    }

    private function processStaticReference(Node $node): void
    {
        $className = null;

        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
            $className = $node->class->toString();
        } elseif ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
            $className = $node->class->toString();
        }

        if ($className && !in_array($className, ['self', 'static', 'parent'])) {
            $this->addDependency('use_class', $className, $node->getLine());
        }
    }

    public function getSymbolCount(): int
    {
        return $this->symbolCount;
    }

    public function getDependencyCount(): int
    {
        return $this->dependencyCount;
    }
}