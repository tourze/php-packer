<?php

declare(strict_types=1);

namespace PhpPacker\Merger;

class VendorFileScanner
{
    /**
     * @return array<string>
     */
    public function findPhpFiles(string $directory): array
    {
        $files = [];

        if (!is_dir($directory)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @param array<string, array<string, mixed>> $allFiles
     * @param array<string> $requiredClasses
     * @return array<string, array<string, mixed>>
     */
    public function filterRequiredFiles(array $allFiles, array $requiredClasses): array
    {
        $filtered = [];

        foreach ($allFiles as $filePath => $fileInfo) {
            $fileClasses = $fileInfo['classes'] ?? [];

            foreach ($requiredClasses as $requiredClass) {
                if (in_array($requiredClass, $fileClasses, true)) {
                    $filtered[$filePath] = $fileInfo;
                    break;
                }
            }
        }

        return $filtered;
    }
}
