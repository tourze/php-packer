<?php

namespace PhpPacker\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeVisitorAbstract;
use ReflectionIntersectionType;
use ReflectionUnionType;

class UseClassCollectorVisitor extends NodeVisitorAbstract
{
    /** @var array<string> */
    private array $mustDependClasses = [];

    /** @var array<string> */
    private array $usedDependClasses = [];

    public function __construct(
        private readonly string $currentFile,
    )
    {
    }

    private function addByVarType(\ReflectionType $type, bool $must = false): void
    {
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $_type) {
                $this->addByVarType($_type);
            }
            return;
        }
        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $_type) {
                $this->addByVarType($_type);
            }
            return;
        }

        if ($type->isBuiltin()) {
            return;
        }

        if ($must) {
            $this->addMustDependClass($type->getName());
        } else {
            $this->addUsedDependClass($type->getName());
        }
    }

    private function addByReflectionClass(\ReflectionClass $classReflection): void
    {
        // 先收集好interface等信息
        $interfaceMethods = [];
        foreach ($classReflection->getInterfaces() as $_interface) {
            foreach ($_interface->getMethods() as $_method) {
                $interfaceMethods[] = $_method->getName();
            }
        }

        foreach ($classReflection->getMethods() as $method) {
            $must = in_array($method->getName(), $interfaceMethods);

            foreach ($method->getParameters() as $param) {
                if ($param->getType()) {
                    $this->addByVarType($param->getType(), $must);
                }
            }

            $type = $method->getReturnType();
            if ($type) {
                $this->addByVarType($type, $must);
            }
        }

        // 父类，肯定要优先加载的啊
        if ($classReflection->getParentClass()) {
            $this->addMustDependClass($classReflection->getParentClass()->getName());
        }
        foreach ($classReflection->getInterfaces() as $interface) {
            $this->addMustDependClass($interface->getName());
        }
    }

    public function enterNode(Node $node)
    {
        // 处理类常量访问 - used
        if ($node instanceof Expr\ClassConstFetch) {
            if ($node->class instanceof Node\Name) {
                $this->addUsedDependClass($node->class->toString());
            }
        }
        // 处理静态方法调用 - used
        elseif ($node instanceof Expr\StaticCall) {
            if ($node->class instanceof Node\Name) {
                $this->addUsedDependClass($node->class->toString());
            }
        }
        // 处理实例化 - used
        elseif ($node instanceof Expr\New_) {
            if ($node->class instanceof Node\Name) {
                $this->addUsedDependClass($node->class->toString());
            }
        }
        // 处理静态属性访问 - used
        elseif ($node instanceof Expr\StaticPropertyFetch) {
            if ($node->class instanceof Node\Name) {
                $this->addUsedDependClass($node->class->toString());
            }
        }

        // 处理instanceof检查 - used
        elseif ($node instanceof Expr\Instanceof_) {
            if ($node->class instanceof Node\Name) {
                $this->addUsedDependClass($node->class->toString());
            }
        }

        // 处理类定义
        if ($node instanceof Node\Stmt\Class_) {
            // 跳过匿名类
            if ($node->isAnonymous()) {
                // 处理匿名类的父类和接口
                if ($node->extends) {
                    $parentClass = $node->extends->toString();
                    $this->addMustDependClass($parentClass);
                }
                foreach ($node->implements as $implement) {
                    $interface = $implement->toString();
                    $this->addMustDependClass($interface);
                }
                return;
            }

            // 获取类名（处理无命名空间的情况）
            $className = $node->namespacedName?->toString() ?? $node->name->toString();

            $this->addByReflectionClass(new \ReflectionClass($className));
        }

        // 处理use语句
        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                if ($use->type === Use_::TYPE_NORMAL) {
                    // use语句引入的类默认为used，除非后续被must使用
                    $this->addUsedDependClass($use->name->toString());
                }
            }
        }

        // 处理Trait使用 - must
        if ($node instanceof Node\Stmt\TraitUse) {
            foreach ($node->traits as $trait) {
                $this->addMustDependClass($trait->toString());
            }
        }

        // 处理方法定义
        if ($node instanceof Node\Stmt\ClassMethod) {
            // 返回值类型
            if ($node->returnType instanceof Node\Name) {
                $this->addUsedDependClass($node->returnType->toString());
            } elseif ($node->returnType instanceof Node\NullableType && $node->returnType->type instanceof Node\Name) {
                $this->addUsedDependClass($node->returnType->type->toString());
            } elseif ($node->returnType instanceof Node\UnionType) {
                foreach ($node->returnType->types as $type) {
                    if ($type instanceof Node\Name) {
                        $this->addUsedDependClass($type->toString());
                    }
                }
            }

            // 参数类型
            foreach ($node->params as $param) {
                if ($param->type instanceof Node\Name) {
                    $this->addUsedDependClass($param->type->toString());
                } elseif ($param->type instanceof Node\NullableType && $param->type->type instanceof Node\Name) {
                    $this->addUsedDependClass($param->type->type->toString());
                } elseif ($param->type instanceof Node\UnionType) {
                    foreach ($param->type->types as $type) {
                        if ($type instanceof Node\Name) {
                            $this->addUsedDependClass($type->toString());
                        }
                    }
                }
            }
        }

        // 处理属性定义 - used（同样的逻辑，属性类型提示也不影响类的基本加载）
        if ($node instanceof Node\Stmt\Property) {
            if ($node->type instanceof Node\Name) {
                $this->addUsedDependClass($node->type->toString());
            } elseif ($node->type instanceof Node\NullableType && $node->type->type instanceof Node\Name) {
                $this->addUsedDependClass($node->type->type->toString());
            } elseif ($node->type instanceof Node\UnionType) {
                foreach ($node->type->types as $type) {
                    if ($type instanceof Node\Name) {
                        $this->addUsedDependClass($type->toString());
                    }
                }
            }
        }

        // 处理catch语句 - used
        if ($node instanceof Node\Stmt\Catch_) {
            foreach ($node->types as $type) {
                $this->addUsedDependClass($type->toString());
            }
        }
    }

    private function isInternalName(string $className): bool
    {
        return in_array($className, [
            'self',
            'static',
            'parent',
            'void',
            'string',
            'int',
            'float',
            'bool',
            'array',
            'callable',
            'mixed',
            'object',
            'null',
            'false',
            'true',
            \Attribute::class,
            \BackedEnum::class,
        ]);
    }

    private function isUserDefined(string $className): bool
    {
        try {
            $reflection = new \ReflectionClass($className);
            return $reflection->isUserDefined();
        } catch (\Throwable $exception) {
            return false;
        }
    }

    private function addMustDependClass(string $class): void
    {
        if ($this->isInternalName($class)) {
            return;
        }

        // 如果类已经在must中，不需要继续处理
        if (in_array($class, $this->mustDependClasses)) {
            return;
        }

        if (!$this->isUserDefined($class)) {
            return;
        }

        $this->mustDependClasses[] = $class;
        $this->mustDependClasses = array_values(array_unique($this->mustDependClasses));

        // 如果一个类被标记为must，则从used中移除
        if (in_array($class, $this->usedDependClasses)) {
            $this->usedDependClasses = array_diff($this->usedDependClasses, [$class]);
            $this->usedDependClasses = array_values(array_unique($this->usedDependClasses));
        }
    }

    private function addUsedDependClass(string $class): void
    {
        if (in_array($class, $this->mustDependClasses)) {
            return;
        }
        if ($this->isInternalName($class)) {
            return;
        }

        if (!$this->isUserDefined($class)) {
            return;
        }

        $this->usedDependClasses[] = $class;
        $this->usedDependClasses = array_values(array_unique($this->usedDependClasses));
    }

    /**
     * @return array<string>
     */
    public function getMustDependClasses(): array
    {
        return $this->mustDependClasses;
    }

    /**
     * @return array<string>
     */
    public function getUsedDependClasses(): array
    {
        return $this->usedDependClasses;
    }

    public function getCurrentFile(): string
    {
        return $this->currentFile;
    }

    /**
     * 合并所有可能用到的类名
     */
    public function getUseClasses(): array
    {
        return array_values(
            array_unique(
                array_merge(
                    $this->getMustDependClasses(),
                    $this->getUsedDependClasses(),
                )
            )
        );
    }
}
