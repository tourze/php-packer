# php-packer

[![PHP Version](https://img.shields.io/badge/php-%5E8.1-777BB4?style=flat-square)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](LICENSE)
[![Build Status](https://img.shields.io/github/actions/workflow/status/tourze/php-monorepo/test.yml?branch=master&style=flat-square)](https://github.com/tourze/php-monorepo/actions)
[![Code Coverage](https://img.shields.io/codecov/c/github/tourze/php-monorepo?style=flat-square)](https://codecov.io/gh/tourze/php-monorepo)

[English](README.md) | [中文](README.zh-CN.md)

PHP单文件打包器 - 将PHP项目打包成单个可执行文件。

## 目录

- [功能特性](#功能特性)
- [安装](#安装)
- [使用方法](#使用方法)
  - [基本工作流程](#基本工作流程)
- [可用命令](#可用命令)
  - [analyze - 分析PHP项目](#analyze-分析php项目)
  - [dependencies - 查询依赖关系](#dependencies-查询依赖关系)
  - [files - 列出所有文件](#files-列出所有文件)
  - [pack - 打包项目](#pack-打包项目)
  - [完整示例](#完整示例)
- [配置选项](#配置选项)
- [工作原理](#工作原理)
- [示例](#示例)
- [高级用法](#高级用法)
- [限制](#限制)
- [开发](#开发)
- [许可证](#许可证)

## 功能特性

- 自动分析PHP文件依赖关系
- 支持PSR-4/PSR-0自动加载规范
- 使用SQLite存储和查询依赖关系
- 智能解析require/include语句
- 处理类继承、接口实现、trait使用
- 生成优化的引导代码
- 支持条件依赖和循环依赖检测
- 模块化命令设计，支持分步操作

## 安装

```bash
composer require tourze/php-packer
```

## 使用方法

### 基本工作流程

```bash
# 1. 分析项目并生成依赖数据库
php vendor/bin/php-packer analyze src/index.php --database=build/app.db

# 2. 查看分析结果
php vendor/bin/php-packer files --database=build/app.db --stats

# 3. 查询特定文件的依赖关系
php vendor/bin/php-packer dependencies src/Application.php --database=build/app.db --tree

# 4. 打包成单个文件
php vendor/bin/php-packer pack --database=build/app.db --output=dist/app.php
```

## 可用命令

### analyze - 分析PHP项目

分析入口文件并生成依赖数据库。

```bash
php-packer analyze <entry-file> [options]

选项：
  --database, -d     数据库文件路径 (默认: ./packer.db)
  --root-path, -r    项目根目录 (默认: 当前目录)
  --composer, -c     composer.json 路径 (默认: <root>/composer.json)
  --autoload         额外的自动加载配置，格式: "psr4:prefix:path"
  --help, -h         显示帮助信息

示例：
  php-packer analyze index.php
  php-packer analyze src/app.php --database=build/myapp.db
  php-packer analyze index.php --autoload="psr4:MyLib:lib/"
```

### dependencies - 查询依赖关系

查询并显示文件的依赖关系。

```bash
php-packer dependencies <file-path> [options]

选项：
  --database, -d     数据库文件路径 (默认: ./packer.db)
  --root-path, -r    项目根目录 (默认: 当前目录)
  --reverse          显示依赖此文件的文件
  --tree             以树形结构显示
  --help, -h         显示帮助信息

示例：
  php-packer dependencies src/Controller.php
  php-packer dependencies src/Model.php --reverse
  php-packer dependencies src/Application.php --tree
```

### files - 列出所有文件

列出数据库中的所有文件及其信息。

```bash
php-packer files [options]

选项：
  --database, -d     数据库文件路径 (默认: ./packer.db)
  --root-path, -r    项目根目录 (默认: 当前目录)
  --type, -t         按类型过滤 (class, trait, interface, script)
  --stats            仅显示统计信息
  --entry            仅显示入口文件
  --sort             排序方式: name, type, size, dependencies (默认: name)
  --help, -h         显示帮助信息

示例：
  php-packer files --stats
  php-packer files --type=class
  php-packer files --sort=dependencies
```

### pack - 打包项目

从数据库读取分析结果并生成打包文件。

```bash
php-packer pack [options]

选项：
  --database, -d     数据库文件路径 (默认: ./packer.db)
  --root-path, -r    项目根目录 (默认: 当前目录)
  --output, -o       输出文件路径 (默认: ./packed.php)
  --compression      启用输出压缩 (gzip)
  --strip-comments   移除代码注释
  --optimize         启用代码优化
  --help, -h         显示帮助信息

示例：
  php-packer pack --output=dist/app.php
  php-packer pack --strip-comments --optimize
  php-packer pack --output=app.phar --compression
```

### 完整示例

打包一个Laravel应用：

```bash
# 分析
php-packer analyze public/index.php \
  --database=build/laravel.db \
  --root-path=/path/to/laravel

# 查看统计
php-packer files --database=build/laravel.db --stats

# 打包
php-packer pack \
  --database=build/laravel.db \
  --output=dist/laravel-packed.php \
  --strip-comments \
  --optimize
```

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

## 配置选项

打包器可以通过多种选项进行配置：

### 数据库选项
- `--database, -d`: 指定SQLite数据库文件路径
- 默认值: `./packer.db`

### 路径选项
- `--root-path, -r`: 设置项目根目录
- `--composer, -c`: 指定composer.json文件位置
- `--autoload`: 添加自定义自动加载规则，格式为 "psr4:prefix:path"

### 输出选项
- `--output`: 指定打包结果的输出文件路径
- `--strip-comments`: 从打包文件中移除注释
- `--optimize`: 启用代码优化

### 配置示例
```bash
php-packer analyze src/app.php \
  --database=build/app.db \
  --root-path=/path/to/project \
  --composer=composer.json \
  --autoload="psr4:MyApp\\:src/"
```

## 高级用法

### 自定义自动加载

您可以添加自定义的PSR-4或PSR-0自动加载规则：

```bash
php-packer analyze src/app.php \
  --autoload="psr4:Custom\\Namespace\\:custom/src/" \
  --autoload="psr0:Legacy_:legacy/lib/"
```

### 优化选项

在打包过程中启用各种优化：

```bash
php-packer pack \
  --database=build/app.db \
  --output=dist/optimized-app.php \
  --strip-comments \
  --optimize
```

### 依赖分析

查询和分析依赖关系进行调试：

```bash
# 显示特定文件的依赖关系树
php-packer dependencies src/Controller/HomeController.php \
  --database=build/app.db \
  --tree

# 列出所有文件及统计信息
php-packer files --database=build/app.db --stats
```

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

## 许可证

MIT
