<?php
include 'functions.php';

class Client
{

    private $client;

    private $channel = 0;

    private $online_list;

    private $gateway = '121.201.69.82';

    private $port = 9509;

    private $proxyServer = '192.168.1.200';

    private $proxyPort = 9509;

    private $pipeClient = array();

    private $pipeAlias = array(
        'toClient' => array(),
        'toServer' => array()
    );

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
                    $sendFd = binToNum(substr($data, 5, 4));
                    
                    if (isset($this->pipeAlias['toClient'][$sendFd])) {
                        $clientfd = $this->pipeAlias['toClient'][$sendFd];
                        
                        $clientSock = $this->pipeClient[$clientfd]['socket'];
                        $connectTime = $this->pipeClient[$clientfd]['connectTime'];
                        
                        if ($clientSock->isConnected()) {
                            
                            if ($this->pipeClient[$clientfd]['isConnected']) {
                                // 转发到client上面
                                $clientSock->send(substr($data, 9));
                            } else {
                                // 把数据加入缓冲区
                                $this->pipeClient[$clientfd]['buffer'][] = substr($data, 9);
                            }
                        } else {
                            // 关闭这个连接
                            unset($this->pipeAlias['toClient'][$sendFd]);
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
                    $sendFd = binToNum(substr($data, 5, 4));
                    
                    // 创建一个客户端连接
                    
                    $socket = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
                    
                    $socket->on('connect', function ($socket) {
                        // 发送缓冲区的数据，改变连接标记
                        $this->pipeClient[$socket->sock]['isConnected']=true;
                        
                        foreach ($this->pipeClient[$socket->sock]['buffer'] as $buffer){
                            $this->pipeClient[$socket->sock]['socket']->send($buffer);
                        }
                        
                        
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
                        //收到客户端返回的消息
                        
                        
                    });
                    
                    $socket->connect($this->proxyServer, $this->proxyPort, - 1);
                    
                    $this->pipeClient[$socket->sock] = array(
                        'socket' => $socket,
                        'connectTime' => time(),
                        'buffer' => array(),
                        'isConnected' => false
                    );
                    
                    break;
                
                case "74":
                    // close
                    $sendFd = binToNum(substr($data, 5, 4));
                    
                    if ($serv->exist($sendFd) && $fd != $sendFd) {
                        
                        $serv->close($sendFd);
                    }
                    
                    break;
          
                case "76":
                    // ping
                    $serv->send($fd, makePongMessage());
                    break;
            }
        } else {
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
    }

    public function onConnect($cli)
    {
        // send auth message
        $cli->send(makeAuthMessage());
    }

    public function onClose($cli)
    {
        echo "Client close connection\n";
    }

    public function onError()
    {}

    public function send($data)
    {
        $this->client->send($data);
    }

    public function isConnected()
    {
        return $this->client->isConnected();
    }
}
$cli = new Client();
$cli->connect();