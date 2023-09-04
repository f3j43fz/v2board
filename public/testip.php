<?php
use zoujingli\ip2region\Ip2Region;

$ip = '1.2.3.4';
$ip2region = new \Ip2Region();
$result = $ip2region->simple($ip);
var_dump($result);
