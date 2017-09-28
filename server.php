<?php
include 'functions.php';

$serv = new swoole_server("0.0.0.0", 9509, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);

$serv->set(array(
    'worker_num' => 4,
    'daemonize' => false,
    'backlog' => 128,
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 600
));

$serv->session = new swoole_table(16);

$serv->session->column('ip', swoole_table::TYPE_STRING, 15);
$serv->session->column('connectTime', swoole_table::TYPE_INT, 4);
$serv->session->column('activeTime', swoole_table::TYPE_INT, 4);

$serv->session->create();

$workerProcess = new swoole_process(function (swoole_process $process) use($serv) {
    while (true) {
        
        foreach ($serv->session as $clientfd => $clientInfo) {
            
            if ($clientInfo['activeTime'] < (time() - 10) || false === $serv->exist($clientfd)) {
                
                $serv->session->del($clientfd);
            } else {
                
                $serv->send($clientfd, makePingMessage());
            }
        }
        
        sleep(5);
    }
});

$serv->addProcess($workerProcess);

$serv->on('workerstart', function ($serv, $id) {
    $serv->lastFd = array();
    $serv->unsendLen = array();
    $serv->leftData = array();
});

$serv->on('connect', function ($serv, $fd) {
    // get client fd
    $counter = count($serv->session);
    
    if ($counter > 0) {
        
        $fdinfo = $serv->connection_info($fd);
        
        $selected = mt_rand(0, $counter - 1);
        
        $i = 0;
        
        foreach ($serv->session as $clientfd => $clientInfo) {
            
            if ($selected === $i && $fdinfo['remote_ip'] != $clientInfo['ip']) {
                $serv->send($clientfd, makeConnectMessage($fd));
                
                break;
            }
            $i ++;
        }
    } else {
        // dont do nothing
    }
});
$serv->on('receive', function ($serv, $fd, $from_id, $data) {
    
    
    if (isset($serv->lastFd[$fd]) && isset($serv->lastFd[$fd]) && $serv->lastFd[$fd] > 0 && $serv->unsendLen[$fd] > 0) {
        // 未处理数据
        $dataLen = strlen($data);
        
        if ($dataLen > $serv->unsendLen[$fd]) {
            // 如果接收的数据长度大于未发送的数据
            if ($serv->exist($serv->lastFd[$fd]))
                $serv->send($serv->lastFd[$fd], substr($data, 0, $serv->unsendLen[$fd]));
            
            
            $data = substr($data, $serv->unsendLen[$fd]);
            
            $serv->lastFd[$fd] = 0;
            
            $serv->unsendLen[$fd] = 0;
            
            
        } else {
            $serv->unsendLen[$fd] -= $dataLen;
            if ($serv->exist($serv->lastFd[$fd]))
                $serv->send($serv->lastFd[$fd], $data);
            
            return;
        }
    }
    
    // 处理遗留数据
    
    if (isset($serv->leftData[$fd]) && strlen($serv->leftData[$fd]) > 0) {
        
        $data = $serv->leftData[$fd] . $data;
        
        $serv->leftData[$fd] = '';
    }
    
    $isCommand=false;
    
    
    for ($j=0;$j<10;$j++){
        
        if (strlen($data) > 12 && binTohex(substr($data, 0, 4)) === "abacadae") {
            
            $isCommand=true;
        
            $method = binTohex(substr($data, 4, 1));
        
            $sendFd = binToNum(substr($data, 5, 4));
        
            $packLen = binToNum(substr($data, 9, 4));
        
            $dataLen = (strlen($data) - 13);
        
            if ($dataLen <= $packLen) {
                $sendData = substr($data, 13);
                $serv->lastFd[$fd] = $sendFd;
                $serv->unsendLen[$fd] = ($packLen - $dataLen);
                
                $data='';
                
            } else {
                $sendData = substr($data, 13, $packLen);
                
                $data = substr($data, 13 + $packLen);
                
                //$serv->leftData[$fd] = substr($data, 13 + $packLen);
                $serv->lastFd[$fd] = $serv->unsendLen[$fd] = 0;
            }
        
        
            // 指令
            switch ($method) {
                case "71":
                    // 注册
                    $fdinfo = $serv->connection_info($fd);
                    $serv->session->set($fd, array(
                        'ip' => $fdinfo['remote_ip'],
                        'connectTime' => time(),
                        'activeTime' => time()
                    ));
        
                    $serv->lastFd[$fd] = 0;
                    $serv->unsendLen[$fd] = 0;
                    $serv->leftData[$fd] = '';
                    break;
                case "72":
                    // 发送消息
                     
                    if ($serv->exist($sendFd)) {
                        if ($fd != $sendFd)
                            $serv->send($sendFd, $sendData);
                    } else {
                        // 告知客户端关闭这个连接
        
                        $serv->send($fd, makeCloseMessage($sendFd));
                    }
        
                    break;
        
                case "73":
                    // connect
        
                    // 服务端不处理此消息
        
                    break;
        
                case "74":
                    // close
        
                    if ($serv->exist($sendFd) && $fd != $sendFd) {
        
                        $serv->close($sendFd);
                    }
        
                    break;
        
                case "76":
                    // ping
                    $serv->send($fd, makePongMessage());
                    break;
        
                case "77":
                    // pong
                    $serv->session->set($fd, array(
                    'activeTime' => time()
                    ));
        
                    break;
            }
        } else {
            
            if ($isCommand){
                $serv->leftData[$fd] = $data;
            }else{
                
                // 普通请求，转发
                
                // get client fd
                $counter = count($serv->session);
                
                if ($counter > 0) {
                
                    $selected = mt_rand(0, $counter - 1);
                
                    $i = 0;
                
                    foreach ($serv->session as $clientfd => $clientInfo) {
                
                        if ($selected === $i) {
                            $serv->send($clientfd, makeSendMessage($fd, $data));
                
                            break;
                        }
                        $i ++;
                    }
                }
                
            }
            
            break;
        }
        
    }
    
    
});

$serv->on('close', function ($serv, $fd) {
    
    $counter = count($serv->session);
    
    if ($counter > 0) {
        
        // update session table.
        if ($serv->session->exist($fd)) {
            $serv->session->del($fd);
        } else {
            
            $selected = mt_rand(0, $counter - 1);
            
            $i = 0;
            
            foreach ($serv->session as $clientfd => $clientInfo) {
                
                if ($selected === $i) {
                    $serv->send($clientfd, makeCloseMessage($fd));
                    
                    break;
                }
                $i ++;
            }
        }
    } else {
        //do nothing
    }
});

$serv->start();