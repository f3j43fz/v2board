<?php

namespace App\Protocols;

use App\Utils\Helper;


class SingBox
{
    public $flag = 'sing';
    private $servers;
    private $user;

    public function __construct($user, $servers)
    {
        $this->user = $user;
        $this->servers = $servers;
    }

    public function handle()
    {
        $servers = $this->servers;
        $user = $this->user;
        $appName = config('v2board.app_name', 'V2Board');
        header("subscription-userinfo: upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}");
        header("content-disposition:attachment;filename*=UTF-8''".rawurlencode($appName));
        $config = json_decode(file_get_contents(base_path() . '/resources/rules/default.singbox.json'), true);
        $outbounds = $config['outbounds'];
        $selectorOutbounds = [];
        $tuic = false;
        $vless = true;
        $vmess = true;
        $shadowsocks = true;
        $trojan = true;
        $hysteria = true;

        $proxy = [];

        foreach ($servers as $item) {

            if ($item['type'] === 'shadowsocks' && $shadowsocks) {
                $proxy = self::buildShadowsocks($user['uuid'], $item);
            }
            if ($item['type'] === 'vmess' && $vmess) {
                $proxy = self::buildVmess($user['uuid'], $item);
            }
            if ($item['type'] === 'vless' && $vless) {
                $proxy = self::buildVless($user['uuid'], $item);
            }
            if ($item['type'] === 'trojan' && $trojan) {
                $proxy = self::buildTrojan($user['uuid'], $item);
            }
            if ($item['type'] === 'hysteria') {
                if ($tuic){
                    $proxy = self::buildTuic($user['uuid'], $item);
                }elseif ($hysteria) {
                    $proxy = self::buildHysteria($user['uuid'], $item);
                }
            }
            if (!empty($proxy)) {
                $outbounds[] = $proxy;
                $selectorOutbounds[] = $item['name'];
            }
        }

        // Find the outbound with tag "proxy" and modify it
        foreach ($outbounds as $key => $outbound) {
            switch ($outbound['type'] ) {
                case 'urltest':
                case 'selector':
                    $outbounds[$key]['outbounds'] = array_merge($outbound['outbounds'], $selectorOutbounds);
            }
        }

        $config['outbounds'] = $outbounds;

        $placeholder = "app_name";
        $config = str_replace($placeholder, $appName, $config);


        return json_encode($config, JSON_PRETTY_PRINT);
    }

    public static function buildShadowsocks($password, $server)
    {
        if ($server['cipher'] === '2022-blake3-aes-128-gcm') {
            $serverKey = Helper::getServerKey($server['created_at'], 16);
            $userKey = Helper::uuidToBase64($password, 16);
            $password = "{$serverKey}:{$userKey}";
        }
        if ($server['cipher'] === '2022-blake3-aes-256-gcm') {
            $serverKey = Helper::getServerKey($server['created_at'], 32);
            $userKey = Helper::uuidToBase64($password, 32);
            $password = "{$serverKey}:{$userKey}";
        }
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'shadowsocks';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['method'] = $server['cipher'];
        $array['password'] = $password;
        return $array;
    }

    public static function buildVless($uuid, $server)
    {
        $array = [];
        $array['type'] = 'vless';
        $array['tag'] = $server['name'];
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['uuid'] = $uuid;
        $array['flow'] = 'xtls-rprx-vision';
        $array['tls'] =[];

        if ($server['tls']) {
            $tls_settings =[];
            if ($server['tls_settings']) {
                $tls_settings = $server['tls_settings'];
            }

            $array['tls'] = [
                'enabled' => true,
                'server_name' => $tls_settings['server_name'],
                'insecure' => false,
                'utls' => [
                    'enabled' => true,
                    'fingerprint' => 'chrome'
                ]
            ];

            if ((int)$server['tls'] === 2) {
                $publicKey = $tls_settings['public_key'];
                $shortID = $tls_settings['short_id'];
                $array['tls'] += [
                    'reality' => [
                        'enabled' => true,
                        'public_key' => $publicKey,
                        'short_id' => $shortID
                    ]
                ];
            }

        }

        $array['packet_encoding'] = 'xudp';

        $array['transport'] =[];
        if ($server['network'] === 'ws') {
            $array['transport']['type'] = 'ws';
            if ($server['network_settings']) {
                $wsSettings = $server['network_settings'];
                if (isset($wsSettings['path']) && !empty($wsSettings['path']))
                    $array['transport']['path'] = $wsSettings['path'];
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host']))
                    $array['transport']['headers'] = $wsSettings['headers'];
            }
        }
        if ($server['network'] === 'grpc') {
            $array['transport']['type'] = 'grpc';
            if ($server['network_settings']) {
                $grpcSettings = $server['network_settings'];
                if (isset($grpcSettings['serviceName'])) $array['transport']['service_name'] = $grpcSettings['serviceName'];
            }
        }

        return $array;
    }

    public static function buildVmess($uuid, $server)
    {
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'vmess';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['uuid'] = $uuid;
        $array['security'] = "auto";
        $array['alter_id'] = 0;

        if ($server['tls']) {
            $tls_settings =[];
            if ($server['tls_settings']) {
                $tls_settings = $server['tls_settings'];
            }

            $array['tls'] = [
                'enabled' => true,
                'server_name' => $tls_settings['server_name'],
                'insecure' => (bool)$tls_settings['allowInsecure']
            ];
        }

        $array['transport'] = [];
        if ($server['network'] === 'ws') {
            $array['transport']['type'] = 'ws';
            if ($server['network_settings']) {
                $wsSettings = $server['network_settings'];
                if (isset($wsSettings['path']) && !empty($wsSettings['path'])){
                    $array['transport']['path'] = $wsSettings['path'];
                }
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host'])){
                    $array['transport']['headers'] = $wsSettings['headers'];
                }
            }
        }

        if ($server['network'] === 'grpc') {
            $array['transport']['type'] = 'grpc';
            if ($server['network_settings']) {
                $grpcSettings = $server['network_settings'];
                if (isset($grpcSettings['serviceName'])){
                    $array['transport']['service_name'] = $grpcSettings['serviceName'];
                }
            }
        }

        return $array;
    }


    public static function buildTrojan($password, $server)
    {
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'trojan';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['password'] = $password;
        $array['tls'] =[];

        if (!empty($server['server_name'])) $array['tls']['server_name'] = $server['server_name'];
        if (!empty($server['allow_insecure'])) $array['tls']['insecure'] = (bool)$server['allow_insecure'];
        return $array;
    }

    public static function buildTuic($password, $server)
    {
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'tuic';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['uuid'] = $password;
        $array['password'] = 'tuic';
        $array['congestion_control'] = 'bbr';
        $array['udp_over_stream'] = true;
        $array['tls'] = [
            'enabled' => true,
            'server_name' => isset($server['server_name']) ? $server['server_name'] : '',
            'insecure' => (bool)$server['insecure'],
            'alpn' => ['h3'],
            'utls' => [
                'enabled' => false,
                'fingerprint' => 'chrome']
        ];
        return $array;
    }

    public static function buildHysteria($password, $server)
    {
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'hysteria';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['auth_str'] = $password;
        $array['up_mbps'] = $server['up_mbps'];
        $array['down_mbps'] = $server['down_mbps'];
        $array['obfs'] = Helper::getServerKey($server['created_at'], 16);
        $array['tls'] = [
            'enabled' => true
        ];
        if (!empty($server['server_name'])) $array['tls']['server_name'] = $server['server_name'];
        if (!empty($server['insecure'])) $array['tls']['insecure'] = (bool)$server['insecure'];
        return $array;
    }

    private function isMatch($exp, $str)
    {
        return @preg_match($exp, $str);
    }

    private function isRegex($exp)
    {
        return @preg_match($exp, null) !== false;
    }
}
