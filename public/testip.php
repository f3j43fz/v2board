<?php
use ip2region\XdbSearcher;

$ip = '123.205.132.153';
$ipPath = base_path() . '/resources/ipdata/ip2region.xdb';
$xdb = $ipPath;
try {
    $region = XdbSearcher::newWithFileOnly($xdb)->search($ip);
    var_dump($region);
} catch (\Exception $e) {
    var_dump($e->getMessage());
}
