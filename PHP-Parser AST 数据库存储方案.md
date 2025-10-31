# PHP-Parser AST 数据库存储方案

## 概述

本方案设计了一个用于存储 [nikic/PHP-Parser](https://github.com/nikic/PHP-Parser) 生成的抽象语法树（AST）的关系型数据库结构。该结构使用 SQLite 作为存储引擎，既保持了 AST 的完整树形结构，又提供了高效的查询和分析能力。

## 设计目标

1. **完整性**：完整保存 AST 的所有节点信息和结构关系
2. **可查询性**：支持高效的代码分析查询
3. **扩展性**：能够适应不同版本 PHP 的 AST 结构变化
4. **性能**：通过合理的索引设计保证查询性能

## 数据库表结构

### 1. AST 文档表 (ast_documents)

存储被解析的 PHP 文件信息。

```sql
CREATE TABLE ast_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_path TEXT NOT NULL,
    file_hash VARCHAR(64),  -- SHA256 hash of the file content
    php_version VARCHAR(10),  -- e.g., '7.4', '8.0', '8.1'
    parse_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    parse_status VARCHAR(20) DEFAULT 'success',  -- success, partial, failed
    error_message TEXT,
    UNIQUE(file_path, file_hash)
);
```

**用途**：
- 记录每个被解析文件的元信息
- 支持增量解析（通过 file_hash 判断文件是否变更）
- 记录解析状态和错误信息

### 2. AST 节点表 (ast_nodes)

核心表，存储所有 AST 节点。

```sql
CREATE TABLE ast_nodes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL,
    parent_id INTEGER,  -- NULL for root nodes
    node_type VARCHAR(100) NOT NULL,  -- e.g., 'Stmt_Function', 'Expr_Variable'
    node_class VARCHAR(255) NOT NULL,  -- Full class name
    position_in_parent INTEGER,  -- Order among siblings
    
    -- Location information
    start_line INTEGER,
    end_line INTEGER,
    start_column INTEGER,
    end_column INTEGER,
    start_file_pos INTEGER,  -- Byte position in file
    end_file_pos INTEGER,
    
    -- Node specific data
    name VARCHAR(255),  -- For named nodes (functions, variables, classes, etc.)
    value TEXT,  -- For literal values
    flags INTEGER,  -- For modifier flags
    
    -- Metadata
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (document_id) REFERENCES ast_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES ast_nodes(id) ON DELETE CASCADE,
    INDEX idx_document_nodes (document_id),
    INDEX idx_parent_nodes (parent_id),
    INDEX idx_node_type (node_type),
    INDEX idx_node_name (name)
);
```

**设计说明**：
- 使用邻接表模型表示树结构（parent_id）
- position_in_parent 保持兄弟节点的顺序
- 位置信息支持精确的代码定位
- name/value/flags 存储常用属性，避免频繁查询属性表

### 3. 节点属性表 (ast_node_attributes)

使用 EAV 模式存储节点的额外属性。

```sql
CREATE TABLE ast_node_attributes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    node_id INTEGER NOT NULL,
    attribute_name VARCHAR(100) NOT NULL,
    attribute_value TEXT,
    attribute_type VARCHAR(50),  -- string, integer, boolean, array, object
    
    FOREIGN KEY (node_id) REFERENCES ast_nodes(id) ON DELETE CASCADE,
    INDEX idx_node_attributes (node_id),
    UNIQUE(node_id, attribute_name)
);
```

**用途**：
- 灵活存储不同节点类型的特有属性
- 避免为每种节点类型创建单独的表

### 4. 节点注释表 (ast_node_comments)

专门存储与节点关联的注释。

```sql
CREATE TABLE ast_node_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    node_id INTEGER NOT NULL,
    comment_type VARCHAR(20) NOT NULL,  -- 'doc', 'inline', 'block'
    comment_text TEXT NOT NULL,
    comment_position VARCHAR(20),  -- 'before', 'after', 'inline'
    line_number INTEGER,
    
    FOREIGN KEY (node_id) REFERENCES ast_nodes(id) ON DELETE CASCADE,
    INDEX idx_node_comments (node_id)
);
```

### 5. 命名空间和导入表

处理 PHP 的命名空间系统。

```sql
CREATE TABLE ast_namespaces (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL,
    namespace_name TEXT,
    start_line INTEGER,
    end_line INTEGER,
    
    FOREIGN KEY (document_id) REFERENCES ast_documents(id) ON DELETE CASCADE,
    INDEX idx_document_namespaces (document_id)
);

CREATE TABLE ast_imports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL,
    namespace_id INTEGER,
    import_type VARCHAR(20) NOT NULL,  -- 'class', 'function', 'const'
    imported_name TEXT NOT NULL,
    alias_name TEXT,
    line_number INTEGER,
    
    FOREIGN KEY (document_id) REFERENCES ast_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (namespace_id) REFERENCES ast_namespaces(id) ON DELETE CASCADE,
    INDEX idx_document_imports (document_id)
);
```

### 6. 符号定义表 (ast_symbols)

建立符号索引，支持快速查找。

```sql
CREATE TABLE ast_symbols (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL,
    node_id INTEGER NOT NULL,
    symbol_type VARCHAR(50) NOT NULL,  -- 'class', 'interface', 'trait', 'function', 'method', 'property', 'constant'
    symbol_name VARCHAR(255) NOT NULL,
    fully_qualified_name TEXT,
    visibility VARCHAR(20),  -- 'public', 'protected', 'private'
    is_static BOOLEAN DEFAULT 0,
    is_abstract BOOLEAN DEFAULT 0,
    is_final BOOLEAN DEFAULT 0,
    parent_symbol_id INTEGER,  -- For nested symbols (e.g., methods in classes)
    
    FOREIGN KEY (document_id) REFERENCES ast_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (node_id) REFERENCES ast_nodes(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_symbol_id) REFERENCES ast_symbols(id) ON DELETE CASCADE,
    INDEX idx_symbol_lookup (symbol_type, symbol_name),
    INDEX idx_fqn_lookup (fully_qualified_name)
);
```

### 7. 类型信息表 (ast_type_info)

存储 PHP 7+ 的类型声明信息。

```sql
CREATE TABLE ast_type_info (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    node_id INTEGER NOT NULL,
    type_kind VARCHAR(50) NOT NULL,  -- 'parameter', 'return', 'property', 'inferred'
    type_string TEXT NOT NULL,  -- e.g., 'string', 'int', 'array', 'MyClass'
    is_nullable BOOLEAN DEFAULT 0,
    is_union BOOLEAN DEFAULT 0,
    is_intersection BOOLEAN DEFAULT 0,
    
    FOREIGN KEY (node_id) REFERENCES ast_nodes(id) ON DELETE CASCADE,
    INDEX idx_node_types (node_id)
);
```

### 8. 依赖关系表 (ast_dependencies)

记录代码之间的依赖关系。

```sql
CREATE TABLE ast_dependencies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_node_id INTEGER NOT NULL,
    target_symbol VARCHAR(255) NOT NULL,
    dependency_type VARCHAR(50) NOT NULL,  -- 'extends', 'implements', 'uses', 'calls', 'instantiates', 'references'
    is_resolved BOOLEAN DEFAULT 0,
    resolved_symbol_id INTEGER,
    
    FOREIGN KEY (source_node_id) REFERENCES ast_nodes(id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_symbol_id) REFERENCES ast_symbols(id) ON DELETE SET NULL,
    INDEX idx_dependency_lookup (target_symbol),
    INDEX idx_source_dependencies (source_node_id)
);
```

## 辅助视图

### 函数和方法视图

```sql
CREATE VIEW v_functions AS
SELECT 
    s.id,
    s.document_id,
    s.symbol_name,
    s.fully_qualified_name,
    s.visibility,
    s.is_static,
    ps.symbol_name as parent_class,
    n.start_line,
    n.end_line,
    d.file_path
FROM ast_symbols s
JOIN ast_nodes n ON s.node_id = n.id
JOIN ast_documents d ON s.document_id = d.id
LEFT JOIN ast_symbols ps ON s.parent_symbol_id = ps.id
WHERE s.symbol_type IN ('function', 'method');
```

### 类继承关系视图

```sql
CREATE VIEW v_class_hierarchy AS
SELECT 
    s.symbol_name as class_name,
    s.fully_qualified_name as class_fqn,
    dep.target_symbol as parent_class,
    dep.dependency_type,
    d.file_path
FROM ast_symbols s
JOIN ast_dependencies dep ON dep.source_node_id = s.node_id
JOIN ast_documents d ON s.document_id = d.id
WHERE s.symbol_type IN ('class', 'interface', 'trait')
AND dep.dependency_type IN ('extends', 'implements', 'uses');
```

## 数据维护

### 触发器示例

自动维护兄弟节点顺序：

```sql
CREATE TRIGGER update_child_positions 
AFTER DELETE ON ast_nodes
BEGIN
    UPDATE ast_nodes 
    SET position_in_parent = position_in_parent - 1
    WHERE parent_id = OLD.parent_id 
    AND position_in_parent > OLD.position_in_parent;
END;
```

## 使用示例

### 1. 存储 AST 节点

```php
// PHP 代码示例
function saveAstToDatabase($ast, $documentId, $parentId = null) {
    foreach ($ast as $index => $node) {
        // 插入节点
        $nodeId = insertNode([
            'document_id' => $documentId,
            'parent_id' => $parentId,
            'node_type' => $node->getType(),
            'node_class' => get_class($node),
            'position_in_parent' => $index,
            'start_line' => $node->getStartLine(),
            'end_line' => $node->getEndLine(),
            // ... 其他属性
        ]);
        
        // 保存节点属性
        foreach ($node->getAttributes() as $name => $value) {
            insertNodeAttribute($nodeId, $name, $value);
        }
        
        // 递归处理子节点
        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->$subNodeName;
            if (is_array($subNode)) {
                saveAstToDatabase($subNode, $documentId, $nodeId);
            } elseif ($subNode instanceof Node) {
                saveAstToDatabase([$subNode], $documentId, $nodeId);
            }
        }
    }
}
```

### 2. 查询示例

#### 查找所有函数定义

```sql
SELECT * FROM v_functions 
WHERE file_path LIKE 'src/%' 
ORDER BY symbol_name;
```

#### 查找特定类的所有方法

```sql
SELECT s.symbol_name, s.visibility, n.start_line
FROM ast_symbols s
JOIN ast_nodes n ON s.node_id = n.id
WHERE s.parent_symbol_id = (
    SELECT id FROM ast_symbols 
    WHERE symbol_name = 'UserController' 
    AND symbol_type = 'class'
)
AND s.symbol_type = 'method';
```

#### 查找所有对特定函数的调用

```sql
SELECT 
    n.start_line,
    n.end_line,
    d.file_path
FROM ast_nodes n
JOIN ast_documents d ON n.document_id = d.id
WHERE n.node_type = 'Expr_FuncCall'
AND n.name = 'deprecated_function';
```

#### 分析类的继承层次

```sql
WITH RECURSIVE inheritance_tree AS (
    -- 基类
    SELECT 
        symbol_name,
        fully_qualified_name,
        0 as depth
    FROM ast_symbols
    WHERE symbol_name = 'BaseController'
    AND symbol_type = 'class'
    
    UNION ALL
    
    -- 递归查找子类
    SELECT 
        s.symbol_name,
        s.fully_qualified_name,
        it.depth + 1
    FROM ast_symbols s
    JOIN ast_dependencies d ON d.source_node_id = s.node_id
    JOIN inheritance_tree it ON d.target_symbol = it.fully_qualified_name
    WHERE d.dependency_type = 'extends'
)
SELECT * FROM inheritance_tree ORDER BY depth, symbol_name;
```

## 性能优化建议

1. **批量插入**：解析大文件时使用事务批量插入节点
2. **懒加载**：按需加载节点属性和注释
3. **缓存**：对符号表进行内存缓存
4. **分区**：对大型代码库考虑按项目或模块分区存储

## 扩展建议

1. **版本控制**：添加版本表支持 AST 的历史记录
2. **缓存表**：添加常用查询的物化视图
3. **全文搜索**：对代码内容建立全文索引
4. **度量表**：存储代码复杂度、耦合度等度量指标

## 总结

这个数据库设计方案提供了一个完整的 PHP AST 存储解决方案，支持：

- 完整保存 AST 结构
- 高效的代码分析查询
- 灵活的扩展能力
- 良好的性能表现

适用于构建代码分析工具、IDE 插件、自动重构工具等应用场景。