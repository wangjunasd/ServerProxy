<?php
include 'functions.php';

class Client
{

    private $client;

    private $activeTime = 0;

    private $gateway = '120.55.38.157';

    private $port = 9509;

    private $proxyServer = '127.0.0.1';

    private $proxyPort = 80;

    private $pipeClient = array();

    private $pipeAlias = array(
        'toClient' => array(),
        'toServer' => array()
    );
    
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
        if (binTohex(substr($data, 0, 4)) === "abacadae") {
            // 指令
            switch (binTohex(substr($data, 4, 1))) {
                case "72":
                    // 发送消息
                    echo "recv msg.\r\n";
                    
                    $sendFd = binToNum(substr($data, 5, 4));
                    
                    $packLen = binToNum(substr($data, 9,4));
                    
                    $dataLen = (strlen($data)-13);
                    
                    print_r($this->pipeAlias);
                    
                    print_r(substr($data, 13));
                    echo "\r\n";
                    
                    if (isset($this->pipeAlias['toClient'][$sendFd])) {
                        $clientfd = $this->pipeAlias['toClient'][$sendFd];
                        
                        $clientSock = $this->pipeClient[$clientfd]['socket'];
                        $connectTime = $this->pipeClient[$clientfd]['connectTime'];
                        
                        if ($clientSock->isConnected()) {
                            
                            if ($this->pipeClient[$clientfd]['isConnected']) {
                                // 转发到client上面
                                $clientSock->send(substr($data, 13));
                            } else {
                                // 把数据加入缓冲区
                                $this->pipeClient[$clientfd]['buffer'][] = substr($data, 13);
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
                    echo "recv connect.\r\n";
                    $sendFd = binToNum(substr($data, 5, 4));
                    
                    // 创建一个客户端连接
                    
                    $socket = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
                    
                    $socket->on('connect', function ($socket) {
                        
                        echo "new client.\r\n";
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
                            
                            $this->client->send(makeConnectMessage($this->pipeAlias['toServer'][$socket->sock]));
                            
                            unset($this->pipeAlias['toClient'][$this->pipeAlias['toServer'][$socket->sock]]);
                            unset($this->pipeAlias['toServer'][$socket->sock]);
                            unset($this->pipeClient[$socket->sock]);
                        }
                    });
                    $socket->on('receive', function ($socket, $data = '') {
                        echo "recv msg from client\r\n";
                        //收到客户端返回的消息
                        if (isset($this->pipeAlias['toServer'][$socket->sock])) {
                            echo "send msg to server\r\n";
                            
                            $this->client->send(makeSendMessage($this->pipeAlias['toServer'][$socket->sock], $data));

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
                    echo "recv close.\r\n";
                    $sendFd = binToNum(substr($data, 5, 4));
                    
                    if (isset($this->pipeAlias['toClient'][$sendFd])) {
                        $this->pipeClient[$this->pipeAlias['toClient'][$sendFd]]['socket']->close();
                    }
                    
                    break;
          
                case "76":
                    // ping
                    echo "recv ping.\r\n";
                    $this->client->send(makePongMessage());
                    break;
                case "77":
                    //pong
                    echo "recv pong.\r\n";
                    $this->activeTime=time();
                    break;
            }
        } else {
            // 未知数据
            print_r($data);
            echo "\r\n";
            // drop
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
        echo "send ping.\r\n";
        
        $this->client->send(makePingMessage());
    }
}
$cli = new Client();
$cli->init();
$cli->connect();