<?php

namespace PhpPacker\Visitor;

use PhpPacker\Ast\Visitor\RenameDebugInfoVisitor as AstRenameDebugInfoVisitor;

/**
 * 兼容性保留类，直接继承AST包中的实现
 * @deprecated 使用 \PhpPacker\Ast\Visitor\RenameDebugInfoVisitor 替代
 */
class RenameDebugInfoVisitor extends AstRenameDebugInfoVisitor
{
}
