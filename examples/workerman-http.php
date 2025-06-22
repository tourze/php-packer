<?php

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;

require_once __DIR__ . '/../../../vendor/autoload.php';

$worker = new Worker('http://0.0.0.0:8080');

$worker->onMessage = function(TcpConnection $connection, Request $request)
{
    // $request为请求对象，这里没有对请求对象执行任何操作直接返回hello给浏览器
    $connection->send("hello");
};

// 运行worker
Worker::runAll();
