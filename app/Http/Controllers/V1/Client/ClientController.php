<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use App\Utils\IPTest;
use Illuminate\Http\Request;
use Ip2Region;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);

        //过滤 UA 白名单
        $UA = $_SERVER['HTTP_USER_AGENT'];
        $UA = strtolower($UA);
        $allowedFlags = ['clash', 'clashforandroid', 'meta', 'shadowrocket', 'sing-box', 'SFA', 'clashforwindows', 'clash-verge', 'loon',  'quantumult', 'sagerNet', 'surge', 'v2ray', 'passwall', 'ssrplus', 'shadowsocks', 'netch'];
        $flagContainsAllowed = false;
        foreach ($allowedFlags as $allowedFlag) {
            if (strpos($UA, $allowedFlag) !== false) {
                $flagContainsAllowed = true;
                break;
            }
        }
        if (!$flagContainsAllowed) {
            header('Location: https://bilibili.com');
            exit();
        }


        $user = $request->user;

        $userIP = $request->ip();

        $ip2region = new \Ip2Region();
        try {
            $result = $ip2region->simple($userIP);
        } catch (\Exception $e) {
            // 处理异常情况
            // 可以输出错误信息或执行其他逻辑
            $result = "未知地区";
        }

//        $info= IPTest::memorySearch($userIP);
//        // 使用 strpos 函数找到第三个 "|" 的位置
//        $pos = strpos($info, '|', strpos($info, '|', strpos($info, '|') + 1) + 1);
//        // 使用 substr 函数获取第三段之后的子串
//        $newInfo = substr($info, $pos + 1);




        // account not expired and is not banned.
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            $this->setSubscribeInfoToServers($servers, $user,$result);
            if ($flag) {
                foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                    $file = 'App\\Protocols\\' . basename($file, '.php');
                    $class = new $file($user, $servers);
                    if (strpos($flag, $class->flag) !== false) {
                        die($class->handle());
                    }
                }
            }
            $class = new General($user, $servers);
            die($class->handle());
        }
    }

    private function setSubscribeInfoToServers(&$servers, $user,$info)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
//        array_unshift($servers, array_merge($servers[0], [
//            'name' => "剩余流量：{$remainingTraffic}",
//        ]));
        array_unshift($servers, array_merge($servers[0], [
            'name' => "您的网络：{$info} ",
        ]));
    }


}
