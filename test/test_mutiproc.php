<?php

require_once __DIR__ . '/../autoload.php';

use Test\MyMutiProc;

/**
 * cli（命令行）执行该脚本文件，捕获传参
 */
if (! empty($argv[1]) && function_exists($argv[1])) {
    call_user_func($argv[1]);
} else {
    echo 'do nothing', "\r\n";
    exit;
}

/**
 * 测试多进程启动
 */
function start()
{
    $proc = new MyMutiProc();
    $proc->start();
}

/**
 * 测试多进程停止
 */
function stop()
{
    $proc = new MyMutiProc();
    $proc->stop();
}

/**
 * 测试多进程重启
 */
function restartWorker()
{
    $proc = new MyMutiProc();
    $proc->restartWorker();
}




