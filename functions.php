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