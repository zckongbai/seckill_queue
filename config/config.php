<?php
$config['redis'] = array(
	'name'	=>	'seckill_http',
    'host'    => "127.0.0.1",
    'port'    => 6379,
    'password' => '',
    'timeout' => 0.25,
    'pconnect' => true,
//    'database' => 1,
);


$config['seckill'] = array(
		'host'	=>	'http://127.0.0.1',
		'port'	=>	'9502',
		'goods_buy'	=>	'http://127.0.0.1:9502/goods/buy',
	);

return $config;
