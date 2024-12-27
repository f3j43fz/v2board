<?php

namespace App\Utils;

use GuzzleHttp\Client;

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
        if (function_exists('com_create_guid') === true) {
            return md5(trim(com_create_guid(), '{}'));
        }
        $data = openssl_random_pseudo_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        if ($format) {
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }
        return md5(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4)) . '-' . time());
    }

    public static function generateOrderNo(): string
    {
        $randomChar = mt_rand(10000, 99999);
        return date('YmdHms') . substr(microtime(), 2, 6) . $randomChar;
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
            $str .= $chars[mt_rand(0, $charsLen)];
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

    public static function getSubscribeUrl($path)
    {
        $subscribeUrls = explode(',', config('v2board.subscribe_url'));
        $subscribeUrl = $subscribeUrls[rand(0, count($subscribeUrls) - 1)];
        if ($subscribeUrl) return $subscribeUrl . $path;
        return url($path);
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

    public static function getUserISP($userIP): string
    {
        // 新的接口URL
        $apiUrl = "https://api.qjqq.cn/api/district?ip={$userIP}";

        // 使用GuzzleHttp或其他HTTP库进行GET请求
        $client = new Client();

        try {
            // 发起请求
            $response = $client->request('GET', $apiUrl);
            $responseBody = json_decode($response->getBody(), true);

            // 检查返回结果
            if (isset($responseBody['code']) && $responseBody['code'] == 200) {
                $ipData = $responseBody['data'] ?? [];

                // 判断国家是否为中国
                if (isset($ipData['country']) && $ipData['country'] === '中国') {
                    // 拼接省份、城市和ISP信息
                    $province = $ipData['prov'] ?? '';
                    $city = $ipData['city'] ?? '';
                    $isp = $ipData['isp'] ?? '';

                    return "{$province}{$city}{$isp}";
                } else {
                    // 如果国家不是中国，调用备用方法
                    return self::getUserISPOutsideChina($userIP);
                }
            } else {
                // API返回错误时的处理
                return 'IP信息查询失败';
            }
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

        // 使用GuzzleHttp或其他HTTP库进行GET请求
        $client = new Client();

        try {
            // 发起请求
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

}
