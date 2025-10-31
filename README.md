# PHP Packer

[![PHP Version](https://img.shields.io/badge/php-%5E8.1-777BB4?style=flat-square)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](LICENSE)
[![Build Status](https://img.shields.io/github/actions/workflow/status/tourze/php-monorepo/test.yml?branch=master&style=flat-square)](https://github.com/tourze/php-monorepo/actions)
[![Code Coverage](https://img.shields.io/codecov/c/github/tourze/php-monorepo?style=flat-square)](https://codecov.io/gh/tourze/php-monorepo)

[English](README.md) | [中文](README.zh-CN.md)

A PHP single-file packer that packages PHP projects into a single executable file.

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Quick Start](#quick-start)
  - [Basic Workflow](#basic-workflow)
- [Available Commands](#available-commands)
  - [analyze - Analyze PHP Project](#analyze-analyze-php-project)
  - [dependencies - Query Dependencies](#dependencies-query-dependencies)
  - [files - List All Files](#files-list-all-files)
  - [pack - Pack Project](#pack-pack-project)
  - [Complete Example](#complete-example)
- [Configuration](#configuration)
- [How It Works](#how-it-works)
- [Examples](#examples)
- [Advanced Usage](#advanced-usage)
- [Limitations](#limitations)
- [Development](#development)
- [License](#license)

## Features

- Automatic PHP file dependency analysis
- Support for PSR-4/PSR-0 autoloading standards
- Use SQLite to store and query dependency relationships
- Intelligent parsing of require/include statements
- Handle class inheritance, interface implementation, and trait usage
- Generate optimized bootstrap code
- Support for conditional dependencies and circular dependency detection
- Modular command design with step-by-step operations

## Installation

```bash
composer require tourze/php-packer
```

## Quick Start

### Basic Workflow

```bash
# 1. Analyze project and generate dependency database
php vendor/bin/php-packer analyze src/index.php --database=build/app.db

# 2. View analysis results
php vendor/bin/php-packer files --database=build/app.db --stats

# 3. Query dependencies for specific files
php vendor/bin/php-packer dependencies src/Application.php --database=build/app.db --tree

# 4. Pack into single file
php vendor/bin/php-packer pack --database=build/app.db --output=dist/app.php
```

## Available Commands

### analyze - Analyze PHP Project

Analyze entry file and generate dependency database.

```bash
php-packer analyze <entry-file> [options]

Options:
  --database, -d     Database file path (default: ./packer.db)
  --root-path, -r    Project root path (default: current directory)
  --composer, -c     Composer.json path (default: <root>/composer.json)
  --autoload         Additional autoload config in format "psr4:prefix:path"
  --help, -h         Show help message

Examples:
  php-packer analyze index.php
  php-packer analyze src/app.php --database=build/myapp.db
  php-packer analyze index.php --autoload="psr4:MyLib:lib/"
```

### dependencies - Query Dependencies

Query and display file dependencies.

```bash
php-packer dependencies <file-path> [options]

Options:
  --database, -d     Database file path (default: ./packer.db)
  --root-path, -r    Project root path (default: current directory)
  --reverse          Show files that depend on this file
  --tree             Display in tree structure
  --help, -h         Show help message

Examples:
  php-packer dependencies src/Controller.php
  php-packer dependencies src/Model.php --reverse
  php-packer dependencies src/Application.php --tree
```

### files - List All Files

List all files and their information in the database.

```bash
php-packer files [options]

Options:
  --database, -d     Database file path (default: ./packer.db)
  --root-path, -r    Project root path (default: current directory)
  --type, -t         Filter by type (class, trait, interface, script)
  --stats            Show only statistics
  --entry            Show only entry files
  --sort             Sort by: name, type, size, dependencies (default: name)
  --help, -h         Show help message

Examples:
  php-packer files --stats
  php-packer files --type=class
  php-packer files --sort=dependencies
```

### pack - Pack Project

Read analysis results from database and generate packed file.

```bash
php-packer pack [options]

Options:
  --database, -d     Database file path (default: ./packer.db)
  --root-path, -r    Project root path (default: current directory)
  --output, -o       Output file path (default: ./packed.php)
  --compression      Enable output compression (gzip)
  --strip-comments   Remove code comments
  --optimize         Enable code optimization
  --help, -h         Show help message

Examples:
  php-packer pack --output=dist/app.php
  php-packer pack --strip-comments --optimize
  php-packer pack --output=app.phar --compression
```

### Complete Example

Packing a Laravel application:

```bash
# Analyze
php-packer analyze public/index.php \
  --database=build/laravel.db \
  --root-path=/path/to/laravel

# View statistics
php-packer files --database=build/laravel.db --stats

# Pack
php-packer pack \
  --database=build/laravel.db \
  --output=dist/laravel-packed.php \
  --strip-comments \
  --optimize
```

## How It Works

1. **Initialization Phase**
    - Create SQLite database
    - Load autoload rules from composer.json
    - Parse configuration files

2. **Analysis Phase**
    - Start analysis from entry file
    - Parse AST using PHP Parser
    - Extract all dependency relationships
    - Iteratively analyze all related files

3. **Resolution Phase**
    - Build dependency graph
    - Resolve symbol references
    - Detect circular dependencies
    - Determine file loading order

4. **Packing Phase**
    - Generate bootstrap code
    - Merge files in dependency order
    - Optimize output code
    - Generate single PHP file

## Configuration

The packer can be configured through various options:

### Database Options
- `--database, -d`: Specify SQLite database file path
- Default: `./packer.db`

### Path Options
- `--root-path, -r`: Set project root directory
- `--composer, -c`: Specify composer.json file location
- `--autoload`: Add custom autoload rules in format "psr4:prefix:path"

### Output Options
- `--output`: Specify output file path for packed result
- `--strip-comments`: Remove comments from packed file
- `--optimize`: Enable code optimization

### Example Configuration
```bash
php-packer analyze src/app.php \
  --database=build/app.db \
  --root-path=/path/to/project \
  --composer=composer.json \
  --autoload="psr4:MyApp\\:src/"
```

## Advanced Usage

### Custom Autoloading

You can add custom PSR-4 or PSR-0 autoload rules:

```bash
php-packer analyze src/app.php \
  --autoload="psr4:Custom\\Namespace\\:custom/src/" \
  --autoload="psr0:Legacy_:legacy/lib/"
```

### Optimization Options

Enable various optimizations during packing:

```bash
php-packer pack \
  --database=build/app.db \
  --output=dist/optimized-app.php \
  --strip-comments \
  --optimize
```

### Dependency Analysis

Query and analyze dependencies for debugging:

```bash
# Show dependency tree for a specific file
php-packer dependencies src/Controller/HomeController.php \
  --database=build/app.db \
  --tree

# List all files with statistics
php-packer files --database=build/app.db --stats
```

## Examples

Check the complete examples in the `examples/` directory:

```bash
cd packages/php-packer
php bin/php-packer examples/packer-config.json
```

This will pack the example project into `build/packed.php`.

## Limitations

- Does not support dynamic includes (e.g., `require $file`)
- Does not support code in eval()
- Requires PHP 8.1+
- Some PHP extensions may need special handling

## Development

Run tests:
```bash
vendor/bin/phpunit
```

Code quality check:
```bash
vendor/bin/phpstan analyse src/
```

## License

MIT