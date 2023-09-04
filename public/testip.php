<?php
use ip2region\Ip2Region;;

$ip = '123.205.132.153';
try {
    $searcher = Ip2Region::newWithFileOnly();
    $region = $searcher->search($ip);
    // 或
    $region = Ip2Region::search($ip);
    var_dump($region);
} catch (\Exception $e) {
    var_dump($e->getMessage());
}
