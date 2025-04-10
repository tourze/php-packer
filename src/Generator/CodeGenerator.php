<?php

namespace PhpPacker\Generator;

use PhpPacker\Ast\AstManagerInterface;
use PhpPacker\Config\Configuration;
use PhpPacker\Visitor\RemoveNamespaceVisitor;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\PrettyPrinter\Standard;
use Psr\Log\LoggerInterface;

class CodeGenerator
{
    private Configuration $config;
    private AstManagerInterface $astManager;
    private LoggerInterface $logger;
    private Standard $printer;

    public function __construct(
        Configuration $config,
        AstManagerInterface $astManager,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->astManager = $astManager;
        $this->logger = $logger;
        $this->printer = new Standard();
    }

    private function generateResourceHolder(string $resFile): \Traversable
    {
        $this->logger->debug('Generating resource holder for ' . $resFile);

        yield new Expression(
            new Assign(
                new Variable('fileName'),
                new Concat(
                    new Dir(),
                    new String_('/' . basename($resFile)),
                ),
            ),
        );

        $md5Hash = md5_file($resFile);
        yield new If_(
            new BooleanAnd(
                new FuncCall(new Name('\file_exists'), [
                    new Arg(new Variable('fileName')),
                ]),
                new NotIdentical(
                    new FuncCall(new Name('\md5_file'), [
                        new Arg(new Variable('fileName')),
                    ]),
                    new String_($md5Hash),
                ),
            ),
            [
                'stmts' => [
                    new Expression(
                        new FuncCall(new Name('\unlink'), [
                            new Arg(new Variable('fileName')),
                        ]),
                    ),
                ],
            ],
        );

        $bContent = base64_encode(file_get_contents($resFile));
        yield new If_(
            new BooleanNot(
                new FuncCall(new Name('\file_exists'), [
                    new Arg(new Variable('fileName')),
                ]),
            ),
            [
                'stmts' => [
                    new Expression(
                        new FuncCall(new Name('\file_put_contents'), [
                            new Arg(new Variable('fileName')),
                            new FuncCall(
                                new Name('\base64_decode'),
                                [
                                    new Arg(new String_($bContent)),
                                ],
                            ),
                        ]),
                    ),
                ],
            ],
        );
    }

    public function generate(AstManagerInterface $astManager, array $phpFiles, array $resourceFiles): string
    {
        $this->logger->debug('Generating code');

        $statements = [];

        // 资源文件，我们增加释放逻辑，放到开头
        $resStatement = [];
        foreach ($resourceFiles as $resFile) {
            foreach ($this->generateResourceHolder($resFile) as $statement) {
                $resStatement[] = $statement;
            }
        }
        if (!empty($resStatement)) {
            $statements[] = new Node\Stmt\Namespace_(null, $resStatement);
        }

        foreach ($phpFiles as $file) {
            $this->logger->debug('Add php file code: ' . $file);
            $statements = array_merge($statements, $astManager->getAst($file));
        }

        // 对于需要使用kphp编译的项目，我们需要去除命名空间
        if ($this->config->shouldRemoveNamespace()) {
            $traverser = $this->astManager->createNodeTraverser();
            $traverser->addVisitor(new RemoveNamespaceVisitor());
            $statements = $traverser->traverse($statements);
        }

        $this->logger->debug('Generating formatted code');
        $code = $this->printer->prettyPrintFile($statements);

        // 这里是针对 Workerman 项目做的特殊兼容
        if ($this->config->shouldRemoveNamespace()) {
            $code = str_replace('\\\\Workerman\\\\Protocols\\\\{$scheme}', 'Workerman_Protocols_{$scheme}', $code);
            $code = str_replace('Workerman\\\\Protocols\\\\{$scheme}', 'Workerman_Protocols_{$scheme}', $code);
        }

        $this->logger->debug('Code generation completed');
        return $code;
    }
}
