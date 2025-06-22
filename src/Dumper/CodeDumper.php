<?php

declare(strict_types=1);

namespace PhpPacker\Dumper;

use PhpPacker\Storage\SqliteStorage;
use Psr\Log\LoggerInterface;

class CodeDumper
{
    private SqliteStorage $storage;
    private LoggerInterface $logger;
    private BootstrapGenerator $bootstrapGenerator;
    private array $config;

    public function __construct(
        SqliteStorage $storage,
        LoggerInterface $logger,
        BootstrapGenerator $bootstrapGenerator,
        array $config = []
    ) {
        $this->storage = $storage;
        $this->logger = $logger;
        $this->bootstrapGenerator = $bootstrapGenerator;
        $this->config = $config;
    }

    public function dump(array $files, string $entryFile, string $outputPath): void
    {
        $this->logger->info('Starting code dump', [
            'files' => count($files),
            'output' => $outputPath,
        ]);

        $content = $this->bootstrapGenerator->generate($files, $entryFile);
        
        $packedFiles = [];
        foreach ($files as $index => $file) {
            $fileContent = $this->processFileContent($file);
            $packedFiles[] = $fileContent;
        }
        
        $content .= $this->wrapPackedFiles($packedFiles);
        
        $entryIndex = $this->findEntryFileIndex($files, $entryFile);
        $content .= $this->generateEntryPoint($entryIndex);
        
        // Close the namespace block opened in bootstrap
        $content .= "\n}\n";
        
        $this->writeOutput($outputPath, $content);
        
        $this->logger->info('Code dump completed', [
            'size' => strlen($content),
            'output' => $outputPath,
        ]);
    }

    private function processFileContent(array $file): string
    {
        $content = $file['content'];
        
        $content = $this->removePhpTags($content);
        
        if ($this->config['optimization']['remove_comments'] ?? false) {
            $content = $this->removeComments($content);
        }
        
        if ($this->config['optimization']['remove_whitespace'] ?? false) {
            $content = $this->minimizeWhitespace($content);
        }
        
        $content = $this->processNamespace($content);
        
        return $content;
    }

    private function removePhpTags(string $content): string
    {
        $content = preg_replace('/^<\?php\s+/i', '', $content);
        
        $content = preg_replace('/\?>\s*$/', '', $content);
        
        return trim($content);
    }

    private function removeComments(string $content): string
    {
        $tokens = token_get_all("<?php\n" . $content);
        $result = '';
        
        foreach ($tokens as $token) {
            if (is_array($token)) {
                switch ($token[0]) {
                    case T_COMMENT:
                        if (strpos($token[1], '@') !== false) {
                            $result .= $token[1];
                        }
                        break;
                    case T_DOC_COMMENT:
                        $result .= $token[1];
                        break;
                    case T_OPEN_TAG:
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

    private function minimizeWhitespace(string $content): string
    {
        // 使用 token_get_all 来安全地压缩代码
        $tokens = token_get_all('<?php ' . $content);
        $result = '';
        $lastToken = null;
        
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $tokenType = $token[0];
                $tokenValue = $token[1];
                
                // 压缩空白字符为单个空格，但保留字符串内容
                if ($tokenType === T_WHITESPACE) {
                    $result .= ' ';
                } else {
                    $result .= $tokenValue;
                }
            } else {
                $result .= $token;
            }
            
            $lastToken = $token;
        }
        
        // 移除开头的 <?php 
        $result = preg_replace('/^\s*<\?php\s*/', '', $result);
        
        // 清理多余的空格
        $result = preg_replace('/\s*([{}();,=])\s*/', '$1', $result);
        $result = preg_replace('/\s+/', ' ', $result);
        
        return trim($result);
    }

    private function processNamespace(string $content): string
    {
        // Check if content starts with namespace declaration
        if (!preg_match('/^\s*namespace\s+([^;{]+)([;{])/', $content, $matches)) {
            // No namespace declaration, return as is
            return $content;
        }
        
        $namespace = trim($matches[1]);
        $delimiter = $matches[2];
        
        // If it already uses braces, don't wrap it again
        if ($delimiter === '{') {
            return $content;
        }
        
        // Convert semicolon-style namespace to brace-style
        $content = preg_replace('/^\s*namespace\s+[^;]+;\s*/', '', $content);
        
        return "namespace $namespace {\n$content\n}";
    }

    private function wrapPackedFiles(array $packedFiles): string
    {
        $wrapped = "\n// Packed files\n";
        
        foreach ($packedFiles as $index => $content) {
            // 确保内容不包含结束标记
            $endMarker = '__PACKED_EOF__';
            while (strpos($content, $endMarker) !== false) {
                $endMarker .= '_' . $index;
            }
            
            $wrapped .= "\$GLOBALS['__PACKED_FILES'][$index] = <<<'$endMarker'\n";
            $wrapped .= $content;  // nowdoc 不需要转义
            $wrapped .= "\n$endMarker;\n\n";
        }
        
        return $wrapped;
    }

    private function findEntryFileIndex(array $files, string $entryFile): int
    {
        foreach ($files as $index => $file) {
            if ($file['is_entry'] || strpos($file['path'], $entryFile) !== false) {
                return $index;
            }
        }
        
        throw new \RuntimeException("Entry file not found in packed files: $entryFile");
    }

    private function generateEntryPoint(int $entryIndex): string
    {
        return <<<PHP

// Execute entry point
(function() {
    eval(\$GLOBALS['__PACKED_FILES'][$entryIndex]);
})();

PHP;
    }

    private function writeOutput(string $outputPath, string $content): void
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        if (file_put_contents($outputPath, $content) === false) {
            throw new \RuntimeException("Failed to write output file: $outputPath");
        }
        
        chmod($outputPath, 0755);
    }
}