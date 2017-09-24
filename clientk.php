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
	private $pipeClient=array();
	private $pipeAlias=array(
	    'toClient'=>array(),
	    'toServer'=>array()
	);
	
	public function init() {
		$this->client = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
        $this->client->on('Connect', array($this, 'onConnect'));
        $this->client->on('Receive', array($this, 'onReceive'));
        $this->client->on('Close', array($this, 'onClose'));
        $this->client->on('Error', array($this, 'onError'));
	}
	
	public function connect() {
		$fp = $this->client->connect($this->gateway, $this->port , 1);
		if( !$fp ) {
			echo "Error: {$fp->errMsg}[{$fp->errCode}]\n";
			return;
		}
	}
	public function onReceive( $cli, $data ) {
	    
	    if (binTohex(substr($data, 0, 4)) === "abacadae") {
	        // 指令
	        switch (binTohex(substr($data, 4, 1))) {
	            case "72":
	                // 发送消息
	                $sendFd = binToNum(substr($data, 5, 4));
	                
	                if (isset($this->pipeAlias['toClient'][$sendFd])){
	                    $clientfd=$this->pipeAlias['toClient'][$sendFd];
	                    
	                    $clientSock=$this->pipeClient[$clientfd];
	                    
	                    if ($clientSock->isConnected()){
	                        //转发到client上面
	                        $clientSock->send(substr($data, 9));
	                    }else{
	                        //关闭这个连接
	                        unset($this->pipeAlias['toClient'][$sendFd]);
	                        unset($this->pipeClient[$clientfd]);
	                        
	                        $cli->send(makeCloseMessage($sendFd));
	                    }
	                }else{
	                    
	                    //关闭这个连接
	                    $cli->send(makeCloseMessage($sendFd));
	                }
	    
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
	    
	    
	    
	
        
    }
    public function onConnect( $cli) {
        //send auth message
        $cli->send(makeAuthMessage());
        
    }
    public function onClose( $cli) {
        echo "Client close connection\n";
    }
    public function onError() {
    }
    public function send($data) {
    	$this->client->send( $data );
    }
    public function isConnected() {
    	return $this->client->isConnected();
    }
}
$cli = new Client();
$cli->connect();