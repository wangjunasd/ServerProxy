<?php
include 'functions.php';

class Client
{

    private $client;

    private $activeTime = 0;

    private $gateway = '120.55.38.157';

    private $port = 9509;

    private $proxyServer = '192.168.199.2';

    private $proxyPort = 80;

    private $pipeClient = array();

    private $pipeAlias = array(
        'toClient' => array(),
        'toServer' => array()
    );
    
    private $lastFd = 0;
    private $unsendLen = 0;
    private $leftData = 0;
    
    private $timer=false;

    public function init()
    {
        $this->client = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
        $this->client->on('Connect', array(
            $this,
            'onConnect'
        ));
        $this->client->on('Receive', array(
            $this,
            'onReceive'
        ));
        $this->client->on('Close', array(
            $this,
            'onClose'
        ));
        $this->client->on('Error', array(
            $this,
            'onError'
        ));
        
        $this->lastFd=0;
        $this->unsendLen=0;
        $this->leftData='';
    }

    public function connect()
    {
        $fp = $this->client->connect($this->gateway, $this->port, 1);
        if (! $fp) {
            echo "Error: {$fp->errMsg}[{$fp->errCode}]\n";
            return;
        }
    }

    public function onReceive($cli, $data)
    {
        
        if ($this->lastFd > 0 && $this->unsendLen > 0) {
            // 未处理数据
            $dataLen = strlen($data);
        
            if ($dataLen > $this->unsendLen) {
                
                if (isset($this->pipeAlias['toClient'][$this->lastFd])) {
                    $clientfd = $this->pipeAlias['toClient'][$this->lastFd];
                
                    $clientSock = $this->pipeClient[$clientfd]['socket'];
                    $connectTime = $this->pipeClient[$clientfd]['connectTime'];
                
                    if ($clientSock->isConnected()) {
                
                        if ($this->pipeClient[$clientfd]['isConnected']) {
                            // 转发到client上面
                            $clientSock->send(substr($data, 0, $this->unsendLen));
                        } else {
                            // 把数据加入缓冲区
                            $this->pipeClient[$clientfd]['buffer'][] = substr($data, 0, $this->unsendLen);
                        }
                
                    }
                }
                
                $data = substr($data, $this->unsendLen);
                
                $this->lastFd = 0;
        
                $this->unsendLen = 0;
        
                
            } else {
                $this->unsendLen -= $dataLen;
                if (isset($this->pipeAlias['toClient'][$this->lastFd])) {
                    $clientfd = $this->pipeAlias['toClient'][$this->lastFd];
                
                    $clientSock = $this->pipeClient[$clientfd]['socket'];
                    $connectTime = $this->pipeClient[$clientfd]['connectTime'];
                
                    if ($clientSock->isConnected()) {
                
                        if ($this->pipeClient[$clientfd]['isConnected']) {
                            // 转发到client上面
                            $clientSock->send($data);
                        } else {
                            // 把数据加入缓冲区
                            $this->pipeClient[$clientfd]['buffer'][] = $data;
                        }
                
                    }
                }
                return;
            }
        }
        
        // 处理遗留数据
        
        if (strlen($this->leftData) > 0) {
        
            $data = $this->leftData . $data;
        
            $this->leftData = '';
        }
        
        if (strlen($data) > 12 && binTohex(substr($data, 0, 4)) === "abacadae") {
            
            
            $method = binTohex(substr($data, 4, 1));
            
            $sendFd = binToNum(substr($data, 5, 4));
            
            $packLen = binToNum(substr($data, 9, 4));
            
            $dataLen = (strlen($data) - 13);
            
            if ($dataLen <= $packLen) {
                $sendData = substr($data, 13);
                $this->lastFd = $sendFd;
                $this->unsendLen = ($packLen - $dataLen);
            } else {
                $sendData = substr($data, 13, $packLen);
                $this->leftData = substr($data, 13 + $packLen);
                $this->lastFd = $this->unsendLen = 0;
            }
            
            
            // 指令
            switch ($method) {
                case "72":
                    // 发送消息
                    echo time()."recv msg.\r\n";
                    
                    if (isset($this->pipeAlias['toClient'][$sendFd])) {
                        $clientfd = $this->pipeAlias['toClient'][$sendFd];
                        
                        $clientSock = $this->pipeClient[$clientfd]['socket'];
                        $connectTime = $this->pipeClient[$clientfd]['connectTime'];
                        
                        if ($clientSock->isConnected()) {
                            
                            if ($this->pipeClient[$clientfd]['isConnected']) {
                                // 转发到client上面
                                $clientSock->send($sendData);
                            } else {
                                // 把数据加入缓冲区
                                $this->pipeClient[$clientfd]['buffer'][] = $sendData;
                            }
                            
                        } else {
                            // 关闭这个连接
                            unset($this->pipeAlias['toClient'][$sendFd]);
                            unset($this->pipeAlias['toServer'][$this->pipeAlias['toClient'][$sendFd]]);
                            unset($this->pipeClient[$clientfd]);
                            
                            $cli->send(makeCloseMessage($sendFd));
                        }
                    } else {
                        // 关闭这个连接
                        $cli->send(makeCloseMessage($sendFd));
                    }
                    
                    break;
                
                case "73":
                    // connect
                    echo time()."recv connect.\r\n";
                    
                    // 创建一个客户端连接
                    
                    $socket = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
                    
                    $socket->on('connect', function ($socket) {
                        
                        echo time()."new client.\r\n";
                        // 发送缓冲区的数据，改变连接标记
                        $this->pipeClient[$socket->sock]['isConnected']=true;
                        
                        foreach ($this->pipeClient[$socket->sock]['buffer'] as $buffer){
                            $this->pipeClient[$socket->sock]['socket']->send($buffer);
                        }
                        
                        $this->pipeClient[$socket->sock]['buffer']=array();
                        
                    });
                    $socket->on('error', function () {});
                    $socket->on('close', function ($socket) {
                        // 客户端发生断开
                        
                        if (isset($this->pipeAlias['toServer'][$socket->sock])) {
                            
                            $this->client->send(makeCloseMessage($this->pipeAlias['toServer'][$socket->sock]));
                            
                            unset($this->pipeAlias['toClient'][$this->pipeAlias['toServer'][$socket->sock]]);
                            unset($this->pipeAlias['toServer'][$socket->sock]);
                            unset($this->pipeClient[$socket->sock]);
                        }
                    });
                    $socket->on('receive', function ($socket, $data = '') {
                        echo time()."recv msg from client\r\n";
                        //收到客户端返回的消息
                        if (isset($this->pipeAlias['toServer'][$socket->sock])) {
                            echo time()."send msg to server\r\n";
                            
                            $this->client->send(makeSendMessage($this->pipeAlias['toServer'][$socket->sock], $data));
                            echo "send bytes:".strlen($data)."\r\n";
                        }else{
                            $socket->close();
                        }
                        
                    });
                    
                    $socket->connect($this->proxyServer, $this->proxyPort, - 1);
                    
                    $this->pipeAlias['toClient'][$sendFd]=$socket->sock;
                    $this->pipeAlias['toServer'][$socket->sock]=$sendFd;
                    
                    $this->pipeClient[$socket->sock] = array(
                        'socket' => $socket,
                        'connectTime' => time(),
                        'buffer' => array(),
                        'isConnected' => false
                    );
                    
                    break;
                
                case "74":
                    // close
                    echo time()."recv close.\r\n";
                    
                    if (isset($this->pipeAlias['toClient'][$sendFd])) {
                        $this->pipeClient[$this->pipeAlias['toClient'][$sendFd]]['socket']->close(); 
                    }
                    
                    break;
          
                case "76":
                    // ping
                    $this->client->send(makePongMessage());
                    break;
                case "77":
                    //pong
                    $this->activeTime=time();
                    break;
            }
        } else {
            // 未知数据
            // drop
            
            echo time()."unknow data\r\n";
            
            echo $data;
            
            echo "\r\n";
        }
    }

    public function onConnect($cli)
    {
        echo "connect server success.\r\n";
        // send auth message
        $cli->send(makeAuthMessage());
        
        $this->activeTime=time();
        
        if ($this->timer){
            swoole_timer_clear($this->timer);
        }
        
        $this->timer=swoole_timer_tick(3000, function(){
            $this->ping();
            
            if ($this->activeTime<(time()-10)){
                $this->client->close();
            }
        });
    }

    public function onClose($cli)
    {
        echo "server close.\r\n";
        $this->init();
        $this->connect();
    }

    public function onError()
    {
        
    }
    
    public function ping(){
       $this->client->send(makePingMessage());
    }
}
$cli = new Client();
$cli->init();
$cli->connect();