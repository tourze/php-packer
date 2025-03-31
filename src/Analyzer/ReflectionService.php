<?php

namespace PhpPacker\Analyzer;

use PhpPacker\Config\Configuration;

class ReflectionService
{
    public function __construct(private readonly Configuration $config)
    {
    }

    /**
     * 读取类所在的文件名
     */
    public function getClassFileName(string $className): string|null
    {
        try {
            $reflection = new \ReflectionClass($className);
            if (!$reflection->isUserDefined()) {
                return null; // 内置的类我们直接不处理
            }

            $fileName = $reflection->getFileName();
            foreach ($this->config->getExclude() as $pattern) {
                if (fnmatch($pattern, $fileName)) {
                    return null; // 忽略匹配的文件
                }
            }
            return $fileName;
        } catch (\Throwable $exception) {
            return null;
        }
    }

    public function getFunctionFileName(string $functionName): string|null
    {
        try {
            $reflection = new \ReflectionFunction($functionName);
            if (!$reflection->isUserDefined()) {
                return null; // 内置的类我们直接不处理
            }

            $fileName = $reflection->getFileName();
            foreach ($this->config->getExclude() as $pattern) {
                if (fnmatch($pattern, $fileName)) {
                    return null; // 忽略匹配的文件
                }
            }
            return $fileName;
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
