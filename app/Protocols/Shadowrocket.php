<?php

namespace App\Protocols;

use App\Utils\Helper;

class Shadowrocket
{
    public $flag = 'shadowrocket';
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

        $uri = '';
        //display remaining traffic and expire date
        $upload = round($user['u'] / (1024*1024*1024), 2);
        $download = round($user['d'] / (1024*1024*1024), 2);
        $ud = $upload + $download;
        $totalTraffic = round($user['transfer_enable'] / (1024*1024*1024), 2);
        $expiredDate = date('Y-m-d', $user['expired_at']);
        $uri .= "STATUS=🚀已用流量:{$ud}GB,总流量:{$totalTraffic}GB💡到期时间:{$expiredDate}\r\n";


        $bulidFree = false;

        foreach ($servers as $item) {

            if(!$bulidFree){
                $uri .= self::buildTrojanFree('防失联节点| '. config('v2board.app_url'));
                $uri .= self::buildTrojanFree('开启 TLS 中的片段功能');
                $uri .= self::buildTrojanFree('才能使用防失联节点');
                $bulidFree = true;
            }

            if ($item['type'] === 'shadowsocks') {
                $uri .= self::buildShadowsocks($user['uuid'], $item);
            }
            if ($item['type'] === 'vmess') {
                $uri .= self::buildVmess($user['uuid'], $item);
            }
            if ($item['type'] === 'vless') {
                $uri .= self::buildVless($user['uuid'], $item);
            }
            if ($item['type'] === 'trojan') {
                $uri .= self::buildTrojan($user['uuid'], $item);
            }
            if ($item['type'] === 'hysteria') {
                $uri .= self::buildhysteria($user['uuid'], $item);
            }
        }
        return base64_encode($uri);
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
        $name = rawurlencode($server['name']);
        $str = str_replace(
            ['+', '/', '='],
            ['-', '_', ''],
            base64_encode("{$server['cipher']}:{$password}")
        );
        return "ss://{$str}@{$server['host']}:{$server['port']}#{$name}\r\n";
    }

    public static function buildVmess($uuid, $server)
    {
        $userinfo = base64_encode('auto:' . $uuid . '@' . $server['host'] . ':' . $server['port']);
        $config = [
            'tfo' => 1,
            'remark' => $server['name'],
            'alterId' => 0
        ];
        if ($server['tls']) {
            $config['tls'] = 1;
            if ($server['tls_settings']) {
                $tls_settings = $server['tls_settings'];
                if (isset($tls_settings['allowInsecure']) && !empty($tls_settings['allowInsecure']))
                    $config['allowInsecure'] = (int)$tls_settings['allowInsecure'];
                if (isset($tls_settings['serverName']) && !empty($tls_settings['serverName']))
                    $config['peer'] = $tls_settings['serverName'];
            }
        }
        if ($server['network'] === 'tcp') {
            if ($server['network_settings']) {
                $tcpSettings = $server['network_settings'];
                if (isset($tcpSettings['header']['type']) && !empty($tcpSettings['header']['type']))
                    $config['obfs'] = $tcpSettings['header']['type'];
                if (isset($tcpSettings['header']['request']['path'][0]) && !empty($tcpSettings['header']['request']['path'][0]))
                    $config['path'] = $tcpSettings['header']['request']['path'][0];
            }
        }
        if ($server['network'] === 'ws') {
            $config['obfs'] = "websocket";
            if ($server['network_settings']) {
                $wsSettings = $server['network_settings'];
                if (isset($wsSettings['path']) && !empty($wsSettings['path']))
                    $config['path'] = $wsSettings['path'];
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host']))
                    $config['obfsParam'] = $wsSettings['headers']['Host'];
            }
        }
        if ($server['network'] === 'grpc') {
            $config['obfs'] = "grpc";
            if ($server['network_settings']) {
                $grpcSettings = $server['network_settings'];
                if (isset($grpcSettings['service_name']) && !empty($grpcSettings['service_name']))
                    $config['path'] = $grpcSettings['service_name'];
            }
            if (isset($tls_settings)) {
                $config['host'] = $tls_settings['serverName'];
            } else {
                $config['host'] = $server['host'];
            }
        }
        $query = http_build_query($config, '', '&', PHP_QUERY_RFC3986);
        $uri = "vmess://{$userinfo}?{$query}";
        $uri .= "\r\n";
        return $uri;
    }



    public static function buildVless($uuid, $server)
    {
        $userinfo = base64_encode('auto:' . $uuid . '@' . $server['host'] . ':' . $server['port']);
        $config = [
            'tfo' => 1,
            'remark' => $server['name'],
            'xtls' => ((int)$server['tls']),
            'pbk' => ((int)$server['tls'] === 2) ? $server['tls_settings']['public_key'] : "",
            'sid' => $server['tls_settings']['short_id'],
        ];
        if ($server['tls']) {
            $config['tls'] = 1;
            if ($server['tls_settings']) {
                $tls_settings = $server['tls_settings'];
                if (!empty($tls_settings['allowInsecure']))
                    $config['allowInsecure'] = (int)$tls_settings['allowInsecure'];
                if (!empty($tls_settings['server_name']))
                    $config['peer'] = $tls_settings['server_name'];
            }
        }
        if ($server['network'] === 'tcp') {
            if ($server['network_settings']) {
                $tcpSettings = $server['network_settings'];
                if (isset($tcpSettings['header']['type']) && !empty($tcpSettings['header']['type']))
                    $config['obfs'] = $tcpSettings['header']['type'];
                if (isset($tcpSettings['header']['request']['path'][0]) && !empty($tcpSettings['header']['request']['path'][0]))
                    $config['path'] = $tcpSettings['header']['request']['path'][0];
            }
        }
        if ($server['network'] === 'ws') {
            $config['obfs'] = "websocket";
            if ($server['network_settings']) {
                $wsSettings = $server['network_settings'];
                if (isset($wsSettings['path']) && !empty($wsSettings['path']))
                    $config['path'] = $wsSettings['path'];
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host']))
                    $config['obfsParam'] = $wsSettings['headers']['Host'];
            }
        }
        if ($server['network'] === 'grpc') {
            $config['obfs'] = "grpc";
            if ($server['network_settings']) {
                $grpcSettings = $server['network_settings'];
                if (isset($grpcSettings['service_name']) && !empty($grpcSettings['service_name']))
                    $config['path'] = $grpcSettings['service_name'];
            }
            if (isset($tls_settings)) {
                $config['host'] = $tls_settings['server_name'];
            } else {
                $config['host'] = $server['host'];
            }
        }

        $query = http_build_query($config, '', '&', PHP_QUERY_RFC3986);
        $uri = "vless://{$userinfo}?{$query}";
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildTrojan($password, $server)
    {

        //trojan://817beb59-6b0b-4bd3-b672-fb43f5a8f72e@www.visa.com.sg:2083?peer=v2ps.bolvinbreniser956.workers.dev&plugin=obfs-local;obfs=websocket;obfs-host=v2ps.bolvinbreniser956.workers.dev;obfs-uri=/?ed=2560#v2ps
        $name = rawurlencode($server['name']);
        $query = http_build_query([
            'allowInsecure' => $server['allow_insecure'],
            'peer' => $server['server_name']
        ]);
        $uri = "trojan://{$password}@{$server['host']}:{$server['port']}?{$query}&tfo=1#{$name}";
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildHysteria($password, $server)
    {
        $uri = "hysteria2://{$password}@{$server['host']}:{$server['port']}";
        $params = [];
        $params[] = "fastopen=0";

        if (isset($server['server_name'])) {
            $params[] = "peer={$server['server_name']}";
        }
        if (isset($server['insecure'])) {
            $params[] = "insecure=" . ($server['insecure'] ? "1" : "0");
        }

        $obfs = Helper::getServerKey($server['created_at'], 16);
        $params[] = "obfs=salamander";
        $params[] = "obfs-password={$obfs}";


        $uri .= "?" . implode("&", $params);
        $remarks = rawurlencode($server['name']);
        $uri .= "#{$remarks}\r\n";
        return $uri;
    }

    private static function buildTrojanFree($name)
    {


        $name = rawurlencode($name);

        $add = 'www.visa.com.sg';

        $password = '817beb59-6b0b-4bd3-b672-fb43f5a8f72e';

        //6个https端口可任意选择(443、8443、2053、2083、2087、2096)
        $ports = [443, 8443, 2053, 2083, 2087, 2096];
        $selectedPort = $ports[array_rand($ports)];

        $query = http_build_query([
            'allowInsecure' => false,
            'peer' => 'v2ps.bolvinbreniser956.workers.dev',
            'sni' => 'v2ps.bolvinbreniser956.workers.dev'
        ]);
        $uri = "trojan://{$password}@{$add}:{$selectedPort}?{$query}";

        $uri .= "&type=ws";

        $path = Helper::encodeURIComponent('/?ed=2560');
        $uri .= "&path={$path}";


        $host = Helper::encodeURIComponent('v2ps.bolvinbreniser956.workers.dev');
        $uri .= "&host={$host}";


        $uri .= "#{$name}\r\n";
        return $uri;

    }



}
