<?php

namespace App\Utils;
use App\Utils\XdbSearcher;

/**
 * class Ip2Region
 * 为兼容老版本调度而创建
 * @author Anyon<zoujingli@qq.com>
 * @datetime 2022/07/18
 */
class Ip2Region
{

    public static function memorySearch($ip)
    {
        return ['city_id' => 0, 'region' => XdbSearcher::newWithFileOnly(base_path() . '/resources/ipdata/ip2region.xdb')->search($ip)];
    }


    public static function simple($ip)
    {
        $geo = self::memorySearch($ip);
        $arr = explode('|', str_replace(['0|'], '|', isset($geo['region']) ? $geo['region'] : ''));
        if (($last = array_pop($arr)) === '内网IP') $last = '';
        return join('', $arr) . (empty($last) ? '' : "【{$last}】");
    }

}
