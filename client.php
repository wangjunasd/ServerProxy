<?php
$serv = new swoole_server("0.0.0.0", 9509, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);

$serv->set(array(
    'worker_num' => 4,
    'daemonize' => false,
    'backlog' => 128,
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 120
));

$serv->session = new swoole_table(131072);

$serv->session->column('isLogin', swoole_table::TYPE_INT, 1);
$serv->session->column('wallet', swoole_table::TYPE_STRING, 40);
$serv->session->column('worker', swoole_table::TYPE_STRING, 20);
$serv->session->column('ip', swoole_table::TYPE_STRING, 15);
$serv->session->column('subscribe', swoole_table::TYPE_STRING, 16);
$serv->session->column('extranonce1', swoole_table::TYPE_STRING, 4);
$serv->session->column('lastTime', swoole_table::TYPE_INT, 4);
$serv->session->column('diff', swoole_table::TYPE_INT, 4);

$serv->session->create();

$serv->on('workerstart', function ($serv, $id) {
    global $redisHost, $redisPort;
    $redis = new redis();
    $redis->connect($redisHost, $redisPort);
    $serv->redis = $redis;
    
    $serv->poolDb = new pooldb();
    
    $serv->walletRPC = new walletRPC();
    
    $serv->jobManager = new jobManager();
});

$serv->on('connect', function ($serv, $fd) {
    if (true === $serv->debug) {
        echo "\r\nnew client connect,fd is " . $fd;
    }
    //
});
$serv->on('receive', function ($serv, $fd, $from_id, $data) {});

$serv->on('close', function ($serv, $fd) {
    
    // update session table.
    if ($serv->session->exist($fd)) {
        $serv->session->del($fd);
    }
});

$serv->start();