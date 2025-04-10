<?php

namespace PhpPacker\Analyzer;

use PhpPacker\Ast\AstManagerInterface;
use PhpPacker\Visitor\UseClassCollectorVisitor;
use PhpPacker\Visitor\UseFunctionCollectorVisitor;
use PhpPacker\Visitor\UseResourceCollectorVisitor;
use Psr\Log\LoggerInterface;
use Yiisoft\Json\Json;

class DependencyAnalyzer
{
    public function __construct(
        private readonly AstManagerInterface $astManager,
        private readonly ReflectionService $reflectionService,
        private readonly LoggerInterface $logger,
    )
    {
    }

    public function getOptimizedFileOrder(string $entryFile): array
    {
        // 第一步：处理必需依赖
        $mustResult = $this->processMustClassDependencies();
        $mustResult = array_diff($mustResult, [$entryFile]);
        $this->logger->debug('step1, Found '.count($mustResult).' optimized files');

        // 第二步：处理可选依赖
        $usedResult = $this->processUsedClassDependencies($mustResult);
        $usedResult = array_diff($usedResult, [$entryFile]);
        $this->logger->debug('step2, Found '.count($mustResult).' optimized files');

        // 第三步：处理函数依赖，函数一般可以放到比较后面
        $funcResult = $this->processFunctionDependencies();
        $funcResult = array_diff($funcResult, [$entryFile]);
        $this->logger->debug('step3, Found '.count($funcResult).' optimized files');

        // 合并结果
        //dd($mustResult, $usedResult, $funcResult);
        return array_unique(array_merge($mustResult, $usedResult, $funcResult, [$entryFile]));
    }

    /**
     * 处理必需依赖，返回拓扑排序后的文件列表
     * @throws \RuntimeException 如果存在循环依赖
     */
    private function processMustClassDependencies(): array
    {
        $mustGraph = [];
        foreach ($this->astManager->getAllAsts() as $file => $ast) {
            $traverser = $this->astManager->createNodeTraverser();

            $visitor = new UseClassCollectorVisitor($file);
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            if (empty($visitor->getMustDependClasses())) {
                continue;
            }

            foreach ($visitor->getMustDependClasses() as $class) {
                $_f = $this->reflectionService->getClassFileName($class);
                if ($_f && $_f !== $file) {
                    if (!isset($mustGraph[$file])) {
                        $mustGraph[$file] = [];
                    }
                    $mustGraph[$file][] = $_f;
                }
            }
        }
        //dump($mustGraph);
        file_put_contents('latest-mustGraph.json', Json::encode($mustGraph));

        $inDegree = [];
        $result = [];
        $queue = new \SplQueue();

        // 初始化入度
        foreach ($mustGraph as $file => $deps) {
            if (!isset($inDegree[$file])) {
                $inDegree[$file] = 0;
            }
            foreach ($deps as $dep) {
                if (!isset($inDegree[$dep])) {
                    $inDegree[$dep] = 0;
                }
                $inDegree[$dep]++;
            }
        }

        // 将入度为0的节点加入队列
        foreach ($inDegree as $file => $degree) {
            if ($degree === 0) {
                $queue->enqueue($file);
            }
        }

        // 拓扑排序
        while (!$queue->isEmpty()) {
            $file = $queue->dequeue();
            $result[] = $file;

            if (isset($mustGraph[$file])) {
                foreach ($mustGraph[$file] as $dep) {
                    $inDegree[$dep]--;
                    if ($inDegree[$dep] === 0) {
                        $queue->enqueue($dep);
                    }
                }
            }
        }

        // 检查是否存在循环依赖
        if (count($result) !== count($inDegree)) {
            $cycles = $this->findCycles($mustGraph);
            $cycleStr = '';
            foreach ($cycles as $cycle) {
                $cycleStr .= "\n" . implode(" -> ", $cycle) . " -> " . $cycle[0];
            }
            throw new \RuntimeException("Circular dependencies detected in must class dependencies: " . $cycleStr);
        }

        $result = array_reverse($result); // 反转结果确保依赖在前
        return $result;
    }

    /**
     * 处理可选依赖，返回处理后的文件列表
     * @param array $processedFiles 已经处理过的文件（必需依赖）
     */
    private function processUsedClassDependencies(array $processedFiles): array
    {
        $usedGraph = [];
        foreach ($this->astManager->getAllAsts() as $file => $ast) {
            $traverser = $this->astManager->createNodeTraverser();

            $visitor = new UseClassCollectorVisitor($file);
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            if (empty($visitor->getUsedDependClasses())) {
                continue;
            }

            foreach ($visitor->getUsedDependClasses() as $class) {
                $_f = $this->reflectionService->getClassFileName($class);
                if ($_f && $_f !== $file) {
                    if (!isset($usedGraph[$file])) {
                        $usedGraph[$file] = [];
                    }
                    $usedGraph[$file][] = $_f;
                }
            }
        }
        //dump($usedGraph);
        file_put_contents('latest-usedGraph.json', Json::encode($usedGraph));

        $result = [];
        $processedSet = array_flip($processedFiles); // 用于快速查找

        // 收集所有未处理的文件
        $remainingFiles = [];
        foreach ($usedGraph as $file => $deps) {
            if (!isset($processedSet[$file])) {
                $remainingFiles[] = $file;
            }
            foreach ($deps as $dep) {
                if (!isset($processedSet[$dep])) {
                    $remainingFiles[] = $dep;
                }
            }
        }
        $remainingFiles = array_unique($remainingFiles);

        // 尝试对可选依赖进行拓扑排序（但允许循环依赖）
        $inDegree = [];
        $queue = new \SplQueue();

        // 初始化入度
        foreach ($remainingFiles as $file) {
            $inDegree[$file] = 0;
        }
        foreach ($usedGraph as $file => $deps) {
            foreach ($deps as $dep) {
                if (isset($inDegree[$dep])) {
                    $inDegree[$dep]++;
                }
            }
        }

        // 将入度为0的节点加入队列
        foreach ($inDegree as $file => $degree) {
            if ($degree === 0) {
                $queue->enqueue($file);
            }
        }

        // 处理队列
        while (!$queue->isEmpty()) {
            $file = $queue->dequeue();
            if (!isset($processedSet[$file])) {
                $result[] = $file;
                $processedSet[$file] = true;
            }

            if (isset($usedGraph[$file])) {
                foreach ($usedGraph[$file] as $dep) {
                    if (isset($inDegree[$dep])) {
                        $inDegree[$dep]--;
                        if ($inDegree[$dep] === 0) {
                            $queue->enqueue($dep);
                        }
                    }
                }
            }
        }

        // 处理剩余的文件（可能存在循环依赖）
        foreach ($remainingFiles as $file) {
            if (!isset($processedSet[$file])) {
                $result[] = $file;
            }
        }

        return $result;
    }

    private function findCycles(array $graph): array
    {
        $visited = [];
        $path = [];
        $cycles = [];

        foreach (array_keys($graph) as $node) {
            if (!isset($visited[$node])) {
                $this->dfs($node, $graph, $visited, $path, $cycles);
            }
        }

        return $cycles;
    }

    /**
     * 文件的依赖处理相对简单
     */
    private function processFunctionDependencies(): array
    {
        $funcGraph = [];
        foreach ($this->astManager->getAllAsts() as $file => $ast) {
            $traverser = $this->astManager->createNodeTraverser();

            $visitor = new UseFunctionCollectorVisitor();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            if (empty($visitor->getUsedFunctions())) {
                continue;
            }
            $funcGraph[] = $file;

            foreach ($visitor->getUsedFunctions() as $function) {
                $_f = $this->reflectionService->getFunctionFileName($function);
                if ($_f && !in_array($_f, $funcGraph)) {
                    $funcGraph[] = $_f;
                }
            }
        }
        //dump($funcGraph);
        file_put_contents('latest-funcGraph.json', Json::encode($funcGraph));

        return array_unique($funcGraph);
    }

    private function dfs(string $node, array $graph, array &$visited, array &$path, array &$cycles): void
    {
        // 标记当前节点为正在访问
        $visited[$node] = true;
        $path[$node] = count($path);

        if (isset($graph[$node])) {
            foreach ($graph[$node] as $neighbor) {
                if (!isset($visited[$neighbor])) {
                    $this->dfs($neighbor, $graph, $visited, $path, $cycles);
                } elseif (isset($path[$neighbor])) {
                    // 找到一个循环
                    $cycle = [];
                    for ($i = $path[$neighbor]; $i < count($path); $i++) {
                        $cycle[] = array_search($i, $path);
                    }
                    $cycles[] = $cycle;
                }
            }
        }

        // 回溯时移除当前节点
        unset($path[$node]);
    }

    /**
     * 传入AST数组，获取所有依赖的文件列表
     */
    public function findDepFiles(string $fileName, array $stmts): \Traversable
    {
        $traverser = $this->astManager->createNodeTraverser();

        $classVisitor = new UseClassCollectorVisitor($fileName);
        $traverser->addVisitor($classVisitor);

        $funcVisitor = new UseFunctionCollectorVisitor();
        $traverser->addVisitor($funcVisitor);

        $traverser->traverse($stmts);

        foreach ($classVisitor->getUseClasses() as $class) {
            $_f = $this->reflectionService->getClassFileName($class);
            if ($_f) {
                yield $_f;
            }
        }

        //dump($funcVisitor->getUsedFunctions());
        foreach ($funcVisitor->getUsedFunctions() as $function) {
            $_f = $this->reflectionService->getFunctionFileName($function);
            if ($_f) {
                yield $_f;
            }
        }
    }

    public function findUsedResources(string $fileName, array $stmts): \Traversable
    {
        $traverser = $this->astManager->createNodeTraverser();

        $resourcesVisitor = new UseResourceCollectorVisitor($fileName);
        $traverser->addVisitor($resourcesVisitor);

        $traverser->traverse($stmts);

        foreach ($resourcesVisitor->getResources() as $resource) {
            if (!file_exists($resource)) {
                continue;
            }
            yield $resource;
        }
    }
}
