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
        $content = preg_replace('/\s+/', ' ', $content);
        
        $content = preg_replace('/\s*([{}();,])\s*/', '$1', $content);
        
        return trim($content);
    }

    private function processNamespace(string $content): string
    {
        if (!preg_match('/^\s*namespace\s+([^;{]+)/', $content, $matches)) {
            return $content;
        }
        
        $namespace = trim($matches[1]);
        $content = preg_replace('/^\s*namespace\s+[^;{]+;?\s*/', '', $content);
        
        return "namespace $namespace {\n$content\n}";
    }

    private function wrapPackedFiles(array $packedFiles): string
    {
        $wrapped = "\n// Packed files\n";
        
        foreach ($packedFiles as $index => $content) {
            $escapedContent = $this->escapeFileContent($content);
            $wrapped .= "\$GLOBALS['__PACKED_FILES'][$index] = <<<'__PACKED_EOF__'\n";
            $wrapped .= $escapedContent;
            $wrapped .= "\n__PACKED_EOF__;\n\n";
        }
        
        return $wrapped;
    }

    private function escapeFileContent(string $content): string
    {
        $content = str_replace('\\', '\\\\', $content);
        
        $content = str_replace(['$', '"'], ['\\$', '\\"'], $content);
        
        return $content;
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