<?php

namespace App\Utils;

use GuzzleHttp\Client;
use ipip\db\City;

class Helper
{
    public static function uuidToBase64($uuid, $length)
    {
        return base64_encode(substr($uuid, 0, $length));
    }

    public static function getServerKey($timestamp, $length)
    {
        return base64_encode(substr(md5($timestamp), 0, $length));
    }

    public static function guid($format = false)
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        if ($format) {
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }
        return md5(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4)) . '-' . time());
    }

    public static function generateOrderNo(): string
    {
        $randomChar = random_int(10000, 99999);
        return date('YmdHms') . substr(microtime(), 2, 6) . $randomChar . bin2hex(random_bytes(4));
    }

    public static function exchange($from, $to)
    {
        $result = file_get_contents('https://api.exchangerate.host/latest?symbols=' . $to . '&base=' . $from);
        $result = json_decode($result, true);
        return $result['rates'][$to];
    }

    public static function randomChar($len, $special = false)
    {
        $chars = array(
            "a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k",
            "l", "m", "n", "o", "p", "q", "r", "s", "t", "u", "v",
            "w", "x", "y", "z", "A", "B", "C", "D", "E", "F", "G",
            "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q", "R",
            "S", "T", "U", "V", "W", "X", "Y", "Z", "0", "1", "2",
            "3", "4", "5", "6", "7", "8", "9"
        );

        if ($special) {
            $chars = array_merge($chars, array(
                "!", "@", "#", "$", "?", "|", "{", "/", ":", ";",
                "%", "^", "&", "*", "(", ")", "-", "_", "[", "]",
                "}", "<", ">", "~", "+", "=", ",", "."
            ));
        }

        $charsLen = count($chars) - 1;
        shuffle($chars);
        $str = '';
        for ($i = 0; $i < $len; $i++) {
            $str .= $chars[random_int(0, $charsLen)];
        }
        return $str;
    }

    public static function multiPasswordVerify($algo, $salt, $password, $hash)
    {
        switch($algo) {
            case 'md5': return md5($password) === $hash;
            case 'sha256': return hash('sha256', $password) === $hash;
            case 'md5salt': return md5($password . $salt) === $hash;
            default: return password_verify($password, $hash);
        }
    }

    public static function emailSuffixVerify($email, $suffixs)
    {
        $suffix = preg_split('/@/', $email)[1];
        if (!$suffix) return false;
        if (!is_array($suffixs)) {
            $suffixs = preg_split('/,/', $suffixs);
        }
        if (!in_array($suffix, $suffixs)) return false;
        return true;
    }

    public static function trafficConvert(int $byte)
    {
        $kb = 1024;
        $mb = 1048576;
        $gb = 1073741824;
        if ($byte > $gb) {
            return round($byte / $gb, 2) . ' GB';
        } else if ($byte > $mb) {
            return round($byte / $mb, 2) . ' MB';
        } else if ($byte > $kb) {
            return round($byte / $kb, 2) . ' KB';
        } else if ($byte < 0) {
            return 0;
        } else {
            return round($byte, 2) . ' B';
        }
    }

    // ① 先求去掉域名的「纯路径」
    public static function buildSubscribePath(): string
    {
        return route('client.subscribe', [], false);
    }

    // ② 再拼域名、随机分流、token
    public static function buildSubscribeUrl(string $token): string
    {
        $path = self::buildSubscribePath() . '?token=' . $token;

        // 支持多个加速域名，用 , 分隔
        $domains = array_filter(explode(',', config('v2board.subscribe_url', '')));
        if ($domains) {
            return $domains[array_rand($domains)] . $path;
        }
        return url($path);      // 没配分流域名就走站点默认域名
    }
    public static function randomPort($range) {
        $portRange = explode('-', $range);
        return rand($portRange[0], $portRange[1]);
    }

    public static function buildShortID()
    {
        $data = 'vless';
        $hash = hash('sha256', $data, true);
        return substr(bin2hex($hash), 0, 16);
    }

    public static function base64EncodeUrlSafe($data)
    {
        $encoded = base64_encode($data);
        return str_replace(['+', '/', '='], ['-', '_', ''], $encoded);
    }

    public static function encodeURIComponent($str) {
        $revert = array('%21'=>'!', '%2A'=>'*', '%27'=>"'", '%28'=>'(', '%29'=>')');
        return strtr(rawurlencode($str), $revert);
    }

    /**
     * 从配置中随机获取一个 socks5 代理
     * @return string|null 返回代理地址或 null
     */
    private static function getProxyConfig(): ?string
    {
        // 从配置文件获取代理服务器配置
        $proxyConfig = config('v2board.proxy_server');

        // 如果配置为空，返回 null
        if (empty($proxyConfig)) {
            return null;
        }

        // 用分号分割多个代理配置
        $proxies = array_filter(explode(';', $proxyConfig));

        // 如果没有可用的代理，返回 null
        if (empty($proxies)) {
            return null;
        }

        // 随机选择一个代理返回（兼容只有一个代理的情况）
        return trim($proxies[array_rand($proxies)]);
    }

    public static function getUserISP($userIP): string
    {
        // 判断地址是否为 IPv6
        if (filter_var($userIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // 调用 IPv6 查询方法
            return self::getUserISPV6($userIP);
        }

        // 离线查询：指定 IP 数据库文件路径
        $ipdbPath = resource_path('ipdata/qqwry.ipdb');

        try {
            // 通过 ipip\db\City 类进行查询（确保已引入该命名空间：use ipip\db\City;）
            $city = new City($ipdbPath);
            $ipInfo = $city->find($userIP, 'CN');

            // 判断如果返回的国家代码字段不为 CN，则调用备用方法
            if (isset($ipInfo[6]) && strtoupper($ipInfo[6]) != 'CN') {
                return self::getUserISPOutsideChina($userIP);
            }

            // 根据返回结果数组获取省区、城市和运营商信息
            $province = $ipInfo[1] ?? '';
            $cityName = $ipInfo[2] ?? '';
            $isp = $ipInfo[5] ?? '';

            return "{$province}{$cityName}{$isp}";
        } catch (\Exception $e) {
            // 捕获异常，处理错误
            return 'IP信息查询异常';
        }
    }


    // 备用的IP归属查询方法
    public static function getUserISPOutsideChina($userIP): string
    {
        // IP.SB API的URL
        $apiUrl = "https://api.ip.sb/geoip/{$userIP}";

        // 获取随机代理配置
        $proxy = self::getProxyConfig();

        // 创建 HTTP 客户端，如果有代理配置则使用代理
        $clientOptions = [];
        if ($proxy) {
            $clientOptions['proxy'] = $proxy;
        }
        $client = new Client($clientOptions);

        try {
            // 通过代理发起请求（如果配置了代理）
            $response = $client->request('GET', $apiUrl);
            $responseBody = json_decode($response->getBody(), true);

            // 检查返回结果是否包含必要的信息
            if (isset($responseBody['country']) && isset($responseBody['isp'])) {
                $country = $responseBody['country'];
                $isp = $responseBody['isp'];

                // 返回拼接后的国家和ISP信息
                return "{$country} {$isp}";
            } else {
                // 返回信息不全时的处理
                return 'IP信息查询失败';
            }
        } catch (\Exception $e) {
            // 捕获异常，处理错误
            return 'IP信息查询异常';
        }
    }

    public static function getUserISPV6($userIP): string
    {
        // 接口 URL
        $apiUrl = "https://api.vore.top/api/IPv6?v6={$userIP}";

        // 获取随机代理配置
        $proxy = self::getProxyConfig();

        // 创建 HTTP 客户端，如果有代理配置则使用代理
        $clientOptions = [];
        if ($proxy) {
            $clientOptions['proxy'] = $proxy;
        }
        $client = new Client($clientOptions);

        try {
            // 通过代理发起请求（如果配置了代理）
            $response = $client->request('GET', $apiUrl);
            $responseBody = json_decode($response->getBody(), true);

            // 检查 code 是否为 200
            if (isset($responseBody['code']) && $responseBody['code'] == 200) {
                $ipData = $responseBody['ipdata'] ?? [];

                // 从 ipData 中获取省、市、ISP
                $province = $ipData['info1'] ?? '';
                $city     = $ipData['info2'] ?? '';
                $isp      = $ipData['isp'] ?? '';

                // 拼接后返回
                return "{$province}{$city}{$isp}";
            } else {
                // API 返回错误时的处理
                return 'IPv6信息查询失败';
            }
        } catch (\Exception $e) {
            // 捕获异常，处理错误
            return 'IPv6信息查询异常';
        }
    }


}
