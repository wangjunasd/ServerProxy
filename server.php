<?php
include 'functions.php';

$serv = new swoole_server("0.0.0.0", 9509, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);

$serv->set(array(
    'worker_num' => 4,
    'daemonize' => false,
    'backlog' => 128,
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 120
));


$serv->session = new swoole_table(16);

$serv->session->column('fd', swoole_table::TYPE_INT, 4);
$serv->session->column('ip', swoole_table::TYPE_STRING, 15);
$serv->session->column('connectTime', swoole_table::TYPE_INT, 4);
$serv->session->column('activeTime', swoole_table::TYPE_INT, 4);

$serv->session->create();

$serv->on('workerstart', function ($serv, $id) {

});

$serv->on('connect', function ($serv, $fd) {

});
$serv->on('receive', function ($serv, $fd, $from_id, $data) {
    if(binTohex(substr($data, 0,4))==="abacadae"){
        //指令
        switch (binTohex(substr($data, 4,1))){
            case "71":
             //注册   
                $fdinfo = $serv->connection_info($fd);
                $serv->session->set(count($serv->session),array(
                    'fd'=>$fd,
                    'ip'=> $fdinfo['remote_ip'],
                    'connectTime'=>time(),
                    'activeTime'=>time()
                ));
               break;
            case "72":
             //发送消息
                $sendFd = binToNum(substr($data, 5,4));
                
                if ($serv->exist($sendFd)){
                    
                    
                    
                }
                
             break;
            
            
            
            
            
        }
        
        
        
    }else{
        //普通请求，转发
        
        
        
        
        
        
    }
    
    
});

$serv->on('close', function ($serv, $fd) {
    
    // update session table.
    if ($serv->session->exist($fd)) {
        $serv->session->del($fd);
    }
});

$serv->start();