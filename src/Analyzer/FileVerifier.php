<?php

declare(strict_types=1);

namespace PhpPacker\Analyzer;

use Psr\Log\LoggerInterface;

class FileVerifier
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * 验证文件是否包含指定的类
     */
    public function verifyClassInFile(string $filePath, string $className, string $fqcn): bool
    {
        try {
            $content = file_get_contents($filePath);
            if (false === $content) {
                return false;
            }

            // 更完善的类名匹配检查，支持各种修饰符和扩展
            // 匹配: class ClassName
            //      abstract class ClassName
            //      final class ClassName
            //      class ClassName extends
            //      class ClassName implements
            $pattern = '/(^|\s)(abstract\s+|final\s+)?(class|interface|trait)\s+' . preg_quote($className, '/') . '(\s|{|$)/m';

            $match = preg_match($pattern, $content);
            if (false !== $match && $match > 0) {
                // 进一步验证命名空间是否匹配
                $namespaceParts = explode('\\', $fqcn);
                array_pop($namespaceParts); // Remove class name
                $expectedNamespace = implode('\\', $namespaceParts);

                if ('' === $expectedNamespace) {
                    // 全局命名空间
                    return true;
                }

                // 检查命名空间声明
                $namespacePattern = '/namespace\s+' . preg_quote($expectedNamespace, '/') . '\s*[;{]/m';
                $namespaceMatch = preg_match($namespacePattern, $content);
                if (false !== $namespaceMatch && $namespaceMatch > 0) {
                    return true;
                }

                $this->logger->debug('Namespace mismatch', [
                    'expected' => $expectedNamespace,
                    'pattern' => $namespacePattern,
                    'content_sample' => substr($content, 0, 200),
                    'file' => $filePath,
                ]);
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 检查文件是否应该被排除分析
     */
    public function shouldExcludeFromAnalysis(string $filePath): bool
    {
        // 排除 Composer 自动生成的 autoload 文件
        $excludePatterns = [
            'vendor/autoload.php',
            'vendor/composer/autoload_real.php',
            'vendor/composer/autoload_static.php',
            'vendor/composer/autoload_classmap.php',
            'vendor/composer/autoload_files.php',
            'vendor/composer/autoload_namespaces.php',
            'vendor/composer/autoload_psr4.php',
            'vendor/composer/ClassLoader.php',
        ];

        foreach ($excludePatterns as $pattern) {
            if (str_ends_with($filePath, $pattern)) {
                $this->logger->debug('Excluding Composer autoload file from analysis', ['file' => $filePath]);

                return true;
            }
        }

        return false;
    }

    /**
     * 验证文件是否为有效的 PHP 文件
     */
    public function verifyFile(string $filePath): bool
    {
        try {
            // 检查文件是否存在
            if (!file_exists($filePath)) {
                return false;
            }

            // 检查文件扩展名
            if (!str_ends_with(strtolower($filePath), '.php')) {
                return false;
            }

            // 尝试读取文件内容
            $content = file_get_contents($filePath);
            if (false === $content) {
                return false;
            }

            // 检查是否为有效的 PHP 文件（至少包含开始标签）
            if (!str_contains($content, '<?php')) {
                return false;
            }

            // 尝试使用 PHP 的内置语法检查
            $syntaxCheck = shell_exec('php -l ' . escapeshellarg($filePath) . ' 2>&1');
            if (null !== $syntaxCheck && is_string($syntaxCheck) && !str_contains($syntaxCheck, 'No syntax errors detected')) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->warning('File verification failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
