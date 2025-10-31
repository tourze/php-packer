<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer;

class ClassScanner
{
    public function __construct()
    {
    }

    /** @return array<string, string> */
    public function scanClassMap(string $path): array
    {
        if (is_file($path)) {
            return $this->scanSingleFile($path);
        }

        if (is_dir($path)) {
            return $this->scanDirectory($path);
        }

        return [];
    }

    /** @return array<string, string> */
    private function scanSingleFile(string $path): array
    {
        if ('php' !== pathinfo($path, PATHINFO_EXTENSION)) {
            return [];
        }

        return $this->scanFileForClasses($path);
    }

    /** @return array<string, string> */
    private function scanDirectory(string $path): array
    {
        $classMap = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && 'php' === $file->getExtension()) {
                $classMap = array_merge($classMap, $this->scanFileForClasses($file->getPathname()));
            }
        }

        return $classMap;
    }

    /** @return array<string, string> */
    public function scanFileForClasses(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if (false === $content) {
            return [];
        }

        $tokens = token_get_all($content);

        return $this->extractClassesFromTokens($tokens, $filePath);
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<string, string>
     */
    private function extractClassesFromTokens(array $tokens, string $filePath): array
    {
        $namespace = '';
        $classes = [];

        for ($i = 0; $i < count($tokens); ++$i) {
            if ($this->isNamespaceToken($tokens[$i])) {
                $result = $this->getNamespaceFromTokens($tokens, $i);
                $namespace = $result['namespace'];
                $i = $result['index'];
                continue;
            }

            if ($this->isClassToken($tokens[$i])) {
                $result = $this->processClassToken($tokens, $i, $namespace, $filePath, $classes);
                $classes = $result['classes'];
                $i = $result['index'];
            }
        }

        return $classes;
    }

    /** @param array{0: int, 1: string, 2: int}|string $token */
    private function isNamespaceToken($token): bool
    {
        return is_array($token) && T_NAMESPACE === $token[0];
    }

    /** @param array{0: int, 1: string, 2: int}|string $token */
    private function isClassToken($token): bool
    {
        return is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT], true);
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, string> $classes
     * @return array{index: int, classes: array<string, string>}
     */
    private function processClassToken(array $tokens, int $index, string $namespace, string $filePath, array $classes): array
    {
        $result = $this->getClassNameFromTokens($tokens, $index);
        if (null === $result['className']) {
            return ['index' => $result['index'], 'classes' => $classes];
        }

        $fqn = '' !== $namespace ? $namespace . '\\' . $result['className'] : $result['className'];
        $classes[$fqn] = $filePath;

        return ['index' => $result['index'], 'classes' => $classes];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{namespace: string, index: int}
     */
    private function getNamespaceFromTokens(array $tokens, int $index): array
    {
        $namespace = '';
        ++$index;

        while (isset($tokens[$index])) {
            if (T_NAME_QUALIFIED === $tokens[$index][0] || T_STRING === $tokens[$index][0]) {
                $namespace .= $tokens[$index][1];
            } elseif ('\\' === $tokens[$index]) {
                $namespace .= '\\';
            } elseif (';' === $tokens[$index] || '{' === $tokens[$index]) {
                break;
            }
            ++$index;
        }

        return ['namespace' => $namespace, 'index' => $index];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{className: ?string, index: int}
     */
    private function getClassNameFromTokens(array $tokens, int $index): array
    {
        ++$index;

        while (isset($tokens[$index])) {
            if (T_STRING === $tokens[$index][0]) {
                return ['className' => $tokens[$index][1], 'index' => $index];
            }
            if (!in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                break;
            }
            ++$index;
        }

        return ['className' => null, 'index' => $index];
    }
}
