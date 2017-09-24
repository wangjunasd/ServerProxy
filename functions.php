<?php
function hexTobin($hex)
{
    return pack('H*', $hex);
}

function binTohex($bin)
{
    $hexAry = unpack('H*', $bin);

    return $hexAry[1];
}

function binToNum($bin){
    
    $hexAry = unpack('N', $bin);
    
    return $hexAry[1];
}

function makeCloseMessage($fd){
    $message=pack('H*','abacadae74');
    $message.=pack('N',$fd);
    $message.="\n";
    return $message;
}

function makeConnectMessage($fd){
    $message=pack('H*','abacadae73');
    $message.=pack('N',$fd);
    $message.="\n";
    return $message;
}

function makeSendMessage($fd,$data){
    $message=pack('H*','abacadae72');
    $message.=pack('N',$fd);
    $message.=$data;
    return $message;
}
function makeAuthMessage(){
    $message=pack('H*','abacadae71');
    $message.="\n";
    return $message;
}

function makeBoardcastMessage($data){
    $message=pack('H*','abacadae75');
    $message.=$data;
    return $message;
}
function makePingMessage(){
    $message=pack('H*','abacadae76');
    $message.="\n";
    return $message;    
}
function makePongMessage(){
    $message=pack('H*','abacadae77');
    $message.="\n";
    return $message;    
}