<?php

namespace App\Utils;

use Exception;
use XdbSearcher;

/**
 * class Ip2Region
 * 为兼容老版本调度而创建
 * @author Anyon<zoujingli@qq.com>
 * @datetime 2022/07/18
 */
class IPTest
{
    public static function memorySearch($ip)
    {
        $dbPath = base_path() . '/resources/ipdata/ip2region.xdb';
        // 1、从 dbPath 加载整个 xdb 到内存。
        $cBuff = XdbSearcher::loadContentFromFile($dbPath);
        if ($cBuff === null) {
            return;
        }

        // 2、使用全局的 cBuff 创建带完全基于内存的查询对象。
        try {
            $searcher = XdbSearcher::newWithBuffer($cBuff);
        } catch (Exception $e) {
            return;
        }

        // 3、查询
        $region = $searcher->search($ip);
        if ($region === null) {
            return;
        }

        return $region;

    }


}
