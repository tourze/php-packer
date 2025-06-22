#!/bin/bash

# PHP Packer 使用示例脚本

echo "PHP Packer 示例 - 打包测试项目"
echo "================================"

# 设置路径
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$SCRIPT_DIR/test-project"
BUILD_DIR="$SCRIPT_DIR/build"
DB_FILE="$BUILD_DIR/example.db"
OUTPUT_FILE="$BUILD_DIR/packed.php"
PHP_PACKER="$SCRIPT_DIR/../bin/php-packer"

# 创建构建目录
mkdir -p "$BUILD_DIR"

# 步骤 1: 分析项目
echo -e "\n1. 分析项目..."
php "$PHP_PACKER" analyze "$PROJECT_DIR/index.php" \
    --database="$DB_FILE" \
    --root-path="$PROJECT_DIR"

# 步骤 2: 查看文件统计
echo -e "\n2. 文件统计信息:"
php "$PHP_PACKER" files --database="$DB_FILE" --stats

# 步骤 3: 查看入口文件依赖
echo -e "\n3. 入口文件依赖关系:"
php "$PHP_PACKER" dependencies index.php \
    --database="$DB_FILE" \
    --root-path="$PROJECT_DIR" \
    --tree

# 步骤 4: 打包
echo -e "\n4. 打包项目..."
php "$PHP_PACKER" pack \
    --database="$DB_FILE" \
    --output="$OUTPUT_FILE" \
    --strip-comments

# 步骤 5: 运行打包后的文件
if [ -f "$OUTPUT_FILE" ]; then
    echo -e "\n5. 运行打包后的文件:"
    echo "---输出开始---"
    php "$OUTPUT_FILE"
    echo -e "\n---输出结束---"
    
    echo -e "\n打包成功！"
    echo "输出文件: $OUTPUT_FILE"
    echo "文件大小: $(du -h "$OUTPUT_FILE" | cut -f1)"
else
    echo -e "\n打包失败！"
    exit 1
fi