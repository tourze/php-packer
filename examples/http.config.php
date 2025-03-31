<?php

return [
    // 入口文件
    'entry' => __DIR__ . '/demo1.php',

    // 输出文件
    'output' => __DIR__ . '/dist/demo1.php',

    // 需要排除的文件/目录
    'exclude' => [
        '*vendor/symfony/error-handler/DebugClassLoader.php',
        'vendor/phpunit/phpunit/src/Framework/MockObject/Runtime/Interface/MockObject.php',
    ],

    // 需要包含的资源文件
    'assets' => [],

    // 是否压缩代码
    'minify' => false,

    // 是否保留注释
    'comments' => true,

    // 调试模式
    'debug' => false,

    // kphp支持
    //'for_kphp' => true,

    // 去除命名空间
    //'remove_namespace' => true,
];
