# php-packer

PHP单文件打包器 - 将PHP项目打包成单个可执行文件。

## 功能特性

- 自动分析PHP文件依赖关系
- 支持PSR-4/PSR-0自动加载规范
- 使用SQLite存储和查询依赖关系
- 智能解析require/include语句
- 处理类继承、接口实现、trait使用
- 生成优化的引导代码
- 支持条件依赖和循环依赖检测

## 安装

```bash
composer require tourze/php-packer
```

## 使用方法

### 命令行使用

```bash
php vendor/bin/php-packer config.json
```

### 配置文件示例

```json
{
    "entry": "src/index.php",
    "output": "dist/packed.php",
    "database": "build/packer.db",
    "include_paths": [
        "src/",
        "lib/"
    ],
    "exclude_patterns": [
        "**/tests/**",
        "**/*Test.php"
    ],
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    },
    "optimization": {
        "remove_comments": true,
        "remove_whitespace": false
    },
    "runtime": {
        "error_reporting": "E_ALL",
        "memory_limit": "256M"
    }
}
```

## 配置说明

| 配置项 | 类型 | 必需 | 说明 |
|--------|------|------|------|
| entry | string | 是 | 入口文件路径 |
| output | string | 是 | 输出文件路径 |
| database | string | 否 | SQLite数据库路径，默认：build/packer.db |
| include_paths | array | 否 | 包含的目录列表 |
| exclude_patterns | array | 否 | 排除的文件模式 |
| autoload | object | 否 | 自定义自动加载规则 |
| optimization | object | 否 | 优化选项 |
| runtime | object | 否 | 运行时配置 |

## 工作原理

1. **初始化阶段**
   - 创建SQLite数据库
   - 加载composer.json中的自动加载规则
   - 解析配置文件

2. **分析阶段**
   - 从入口文件开始分析
   - 使用PHP Parser解析AST
   - 提取所有依赖关系
   - 迭代分析所有相关文件

3. **解析阶段**
   - 构建依赖图
   - 解析符号引用
   - 检测循环依赖
   - 确定文件加载顺序

4. **打包阶段**
   - 生成引导代码
   - 按依赖顺序合并文件
   - 优化输出代码
   - 生成单个PHP文件

## 示例

查看 `examples/` 目录中的完整示例：

```bash
cd packages/php-packer
php bin/php-packer examples/packer-config.json
```

这将把示例项目打包成 `build/packed.php`。

## 限制

- 不支持动态包含（如 `require $file`）
- 不支持eval()中的代码
- 需要PHP 8.1+
- 某些PHP扩展可能需要特殊处理

## 开发

运行测试：
```bash
vendor/bin/phpunit
```

代码质量检查：
```bash
vendor/bin/phpstan analyse src/
```
