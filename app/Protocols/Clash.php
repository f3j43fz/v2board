<?php

namespace App\Protocols;

use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use App\Jobs\DomainToIPJob;
use App\Utils\Helper;
use phpDocumentor\Reflection\Types\Self_;
use Symfony\Component\Yaml\Yaml;

class Clash
{
    public $flag = 'clash';
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
        $servers = $this->domainToIP($servers);
        $user = $this->user;
        $appName = config('v2board.app_name', 'V2Board');
        header("subscription-userinfo: upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}");
        header('profile-update-interval: 24');
        header("content-disposition:attachment;filename*=UTF-8''".rawurlencode($appName));
        header("profile-web-page-url:" . "https://bilibili.com");
        $defaultConfig = base_path() . '/resources/rules/default.clash.yaml';
        $customConfig = base_path() . '/resources/rules/custom.clash.yaml';
        if (\File::exists($customConfig)) {
            $config = Yaml::parseFile($customConfig);
        } else {
            $config = Yaml::parseFile($defaultConfig);
        }
        $proxy = [];
        $proxies = [];

        foreach ($servers as $item) {
            if ($item['type'] === 'shadowsocks'
                && in_array($item['cipher'], [
                    'aes-128-gcm',
                    'aes-192-gcm',
                    'aes-256-gcm',
                    'chacha20-ietf-poly1305'
                ])
            ) {
                array_push($proxy, self::buildShadowsocks($user['uuid'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === 'vmess') {
                array_push($proxy, self::buildVmess($user['uuid'], $item));
                array_push($proxies, $item['name']);
            }

//            if ($item['type'] === 'vless') {
//                array_push($proxy, self::buildVless($user['uuid'], $item));
//                array_push($proxies, $item['name']);
//            }

            if ($item['type'] === 'trojan') {
                array_push($proxy, self::buildTrojan($user['uuid'], $item));
                array_push($proxies, $item['name']);
            }
        }

        $config['proxies'] = array_merge($config['proxies'] ? $config['proxies'] : [], $proxy);
        foreach ($config['proxy-groups'] as $k => $v) {
            if (!is_array($config['proxy-groups'][$k]['proxies'])) $config['proxy-groups'][$k]['proxies'] = [];
            $isFilter = false;
            foreach ($config['proxy-groups'][$k]['proxies'] as $src) {
                foreach ($proxies as $dst) {
                    if (!$this->isRegex($src)) continue;
                    $isFilter = true;
                    $config['proxy-groups'][$k]['proxies'] = array_values(array_diff($config['proxy-groups'][$k]['proxies'], [$src]));
                    if ($this->isMatch($src, $dst)) {
                        array_push($config['proxy-groups'][$k]['proxies'], $dst);
                    }
                }
                if ($isFilter) continue;
            }
            if ($isFilter) continue;
            $config['proxy-groups'][$k]['proxies'] = array_merge($config['proxy-groups'][$k]['proxies'], $proxies);
        }

        $config['proxy-groups'] = array_filter($config['proxy-groups'], function($group) {
            return $group['proxies'];
        });
        $config['proxy-groups'] = array_values($config['proxy-groups']);
        // Force the current subscription domain to be a direct rule
        $subsDomain = $_SERVER['HTTP_HOST'];
        if ($subsDomain) {
            array_unshift($config['rules'], "DOMAIN,{$subsDomain},DIRECT");
        }

        $yaml = Yaml::dump($config, 2, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        $yaml = str_replace('$app_name', config('v2board.app_name', 'V2Board'), $yaml);
        return $yaml;
    }

    public static function buildShadowsocks($uuid, $server)
    {
        $array = [];
        $array['name'] = $server['name'];
        $array['type'] = 'ss';
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];
        $array['cipher'] = $server['cipher'];
        $array['password'] = $uuid;
        $array['udp'] = true;
        return $array;
    }

    public static function buildVmess($uuid, $server)
    {
        $array = [];
        $array['name'] = $server['name'];
        $array['type'] = 'vmess';
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];
        $array['uuid'] = $uuid;
        $array['alterId'] = 0;
        $array['cipher'] = 'auto';
        $array['udp'] = true;

        if ($server['tls']) {
            $array['tls'] = true;
            if ($server['tls_settings']) {
                $tls_settings = $server['tls_settings'];
                if (isset($tls_settings['allowInsecure']) && !empty($tls_settings['allowInsecure']))
                    $array['skip-cert-verify'] = ($tls_settings['allowInsecure'] ? true : false);
                if (isset($tls_settings['serverName']) && !empty($tls_settings['serverName']))
                    $array['servername'] = $tls_settings['serverName'];
            }
        }
        if ($server['network'] === 'tcp') {
            $tcpSettings = $server['network_settings'];
            if (isset($tcpSettings['header']['type'])) $array['network'] = $tcpSettings['header']['type'];
            if (isset($tcpSettings['header']['request']['path'][0])) $array['http-opts']['path'] = $tcpSettings['header']['request']['path'][0];
        }
        if ($server['network'] === 'ws') {
            $array['network'] = 'ws';
            if ($server['network_settings']) {
                $wsSettings = $server['network_settings'];
                $array['ws-opts'] = [];
                if (isset($wsSettings['path']) && !empty($wsSettings['path']))
                    $array['ws-opts']['path'] = $wsSettings['path'];
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host']))
                    $array['ws-opts']['headers'] = ['Host' => $wsSettings['headers']['Host']];
                if (isset($wsSettings['path']) && !empty($wsSettings['path']))
                    $array['ws-path'] = $wsSettings['path'];
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host']))
                    $array['ws-headers'] = ['Host' => $wsSettings['headers']['Host']];
            }
        }
        if ($server['network'] === 'grpc') {
            $array['network'] = 'grpc';
            if ($server['network_settings']) {
                $grpcSettings = $server['network_settings'];
                $array['grpc-opts'] = [];
                if (isset($grpcSettings['service_name'])) {
                    $array['grpc-opts'] = ["grpc-service-name" => $grpcSettings['service_name']];
                }
            }
        }

        return $array;
    }

    public static function buildVless($uuid, $server)
    {
        $array = [];
        $array['name'] = $server['name'];
        $array['type'] = 'vless';
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];
        $array['uuid'] = $uuid;
        $array['udp'] = true;
        $array['xudp'] = true;
        $array['flow'] = !empty($server['flow']) ? $server['flow']: "";
        if ($server['tls']) {
            $array['tls'] = true;
            if ($server['tls_settings']) {
                $tls_settings = $server['tls_settings'];
                if (!empty($tls_settings['allowInsecure']))
                    $array['skip-cert-verify'] = (bool)$tls_settings['allowInsecure'];
                if (!empty($tls_settings['server_name']))
                    $array['servername'] = $tls_settings['server_name'];
                if ((int)$server['tls'] === 2) {
                    $publicKey = $tls_settings['public_key'];
                    $shortID = $tls_settings['short_id'];
                    $array['reality-opts'] = [
                        'public-key' => $publicKey,
                        'short-id' => $shortID
                    ];
                    $array['client-fingerprint'] = 'chrome';
                }
            }
        }
        if ($server['network'] === 'tcp') {
            $tcpSettings = $server['network_settings'];
            if (isset($tcpSettings['header']['type'])) $array['network'] = $tcpSettings['header']['type'];
            if (isset($tcpSettings['header']['request']['path'][0])) $array['http-opts']['path'] = $tcpSettings['header']['request']['path'][0];
        }
        if ($server['network'] === 'ws') {
            $array['network'] = 'ws';
            if ($server['network_settings']) {
                $wsSettings = $server['network_settings'];
                $array['ws-opts'] = [];
                if (isset($wsSettings['path']) && !empty($wsSettings['path']))
                    $array['ws-opts']['path'] = $wsSettings['path'];
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host']))
                    $array['ws-opts']['headers'] = ['Host' => $wsSettings['headers']['Host']];
                if (isset($wsSettings['path']) && !empty($wsSettings['path']))
                    $array['ws-path'] = $wsSettings['path'];
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host']))
                    $array['ws-headers'] = ['Host' => $wsSettings['headers']['Host']];
            }
        }
        if ($server['network'] === 'grpc') {
            $array['network'] = 'grpc';
            if ($server['network_settings']) {
                $grpcSettings = $server['network_settings'];
                $array['grpc-opts'] = [];
                if (isset($grpcSettings['service_name'])) {
                    $array['grpc-opts'] = ["grpc-service-name" => $grpcSettings['service_name']];
                }
            }
        }
        return $array;
    }

    public static function buildTrojan($password, $server)
    {
        $array = [];
        $array['name'] = $server['name'];
        $array['type'] = 'trojan';
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];
        $array['password'] = $password;
        $array['udp'] = true;
        if (!empty($server['server_name'])) $array['sni'] = $server['server_name'];
        if (!empty($server['allow_insecure'])) $array['skip-cert-verify'] = ($server['allow_insecure'] ? true : false);
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

    private function domainToIP($servers)
    {
        ini_set('memory_limit', -1);
        foreach ($servers as &$item) {
            $domain = $item['host'];
            $cacheKey = CacheKey::get('Domain_IP', $domain);
            $ip = Cache::get($cacheKey);

            if (!$ip) {
                Queue::push(new DomainToIPJob($item, $domain, $cacheKey));
            } else {
                $item['host'] = $ip;
            }
        }

        return $servers;
    }
}
