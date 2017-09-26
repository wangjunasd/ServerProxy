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


$workerProcess = new swoole_process(function (swoole_process $process) use($serv)
{
    while (true){
        
        foreach ($serv->session as $clientfd=>$clientInfo){
            
            if ($clientInfo['activeTime']<(time()-10) || false===$serv->exist($clientfd)){
                
               $serv->session->del($clientfd);
                
            }else{
                
                $serv->send($clientfd,makePingMessage());
            }
        
        }
        
        sleep(5);
    }
    
});

$serv->addProcess($workerProcess);

$serv->on('workerstart', function ($serv, $id) {});

$serv->on('connect', function ($serv, $fd) {
    //get client fd
    $counter=count($serv->session);
    
    if ($counter>0){
        
        $fdinfo = $serv->connection_info($fd);
        
        $selected=mt_rand(0, $counter-1);
        
        $i=0;
        
        foreach ($serv->session as $clientfd=>$clientInfo){
        
            if ($selected===$i && $fdinfo['remote_ip']!=$clientInfo['ip']){
                $serv->send($clientfd,makeConnectMessage($fd));
        
                break;
            }
            $i++;
        }
        
        
    }else{
        //dont do nothing
    }
});
$serv->on('receive', function ($serv, $fd, $from_id, $data) {
    
    if (binTohex(substr($data, 0, 4)) === "abacadae") {
        // 指令
        switch (binTohex(substr($data, 4, 1))) {
            case "71":
                // 注册
                $fdinfo = $serv->connection_info($fd);
                $serv->session->set($fd, array(
                    'ip' => $fdinfo['remote_ip'],
                    'connectTime' => time(),
                    'activeTime' => time()
                ));
                break;
            case "72":
                // 发送消息
                
                echo "rev msg\r\n";
                $sendFd = binToNum(substr($data, 5, 4));
                
                if ($serv->exist($sendFd)) {
                    if ($fd!=$sendFd)
                     $serv->send($sendFd, substr($data, 9));
                } else {
                    // 告知客户端关闭这个连接
                    
                    $serv->send($fd, makeCloseMessage($sendFd));
                }
                
                print_r(substr($data, 9));
                
                break;
            
            case "73":
                // connect
                
                // 服务端不处理此消息
                
                break;
            
            case "74":
                // close
                $sendFd = binToNum(substr($data, 5, 4));
                
                if ($serv->exist($sendFd) && $fd!=$sendFd) {
                    
                    $serv->close($sendFd);
                }
                
                break;
            
            case "75":
                // boardcast
                
                $message = substr($data, 5);
                
                $start_fd = 0;
                while (true) {
                    $conn_list = $serv->connection_list($start_fd, 10);
                    if ($conn_list === false or count($conn_list) === 0) {
                        break;
                    }
                    $start_fd = end($conn_list);
                    
                    foreach ($conn_list as $curFd) {
                        if ($fd != $curFd)
                            $serv->send($curFd, $message);
                    }
                }
                
                break;
                
            case "76":
                //ping
                $serv->send($fd,makePongMessage());
                break;
                
            case "77":
                //pong
                $serv->session->set($fd,array(
                    'activeTime'=>time()
                ));
                
                break;
        }
    } else {
        // 普通请求，转发
        
        //get client fd
        $counter=count($serv->session);
        
        if ($counter>0){
            
            $selected=mt_rand(0, $counter-1);
            
            $i=0;
            
            foreach ($serv->session as $clientfd=>$clientInfo){
            
                if ($selected===$i){
                    $serv->send($clientfd,makeSendMessage($fd, $data));
            
                    break;
                }
                $i++;
            }            
        }
        

    }
});

$serv->on('close', function ($serv, $fd) {
    
    $counter=count($serv->session);
    
    if ($counter>0){
        
        // update session table.
        if ($serv->session->exist($fd)) {
            $serv->session->del($fd);
        }else{
            
            $selected=mt_rand(0, $counter-1);
            
            $i=0;
            
            foreach ($serv->session as $clientfd=>$clientInfo){
            
                if ($selected===$i){
                    $serv->send($clientfd,makeCloseMessage($fd));
            
                    break;
                }
                $i++;
            }      
        }
    
    }else{
        //dont do nothing
    }
});

$serv->start();