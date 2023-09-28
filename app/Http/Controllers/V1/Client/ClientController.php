<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\Tokenrequest;
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
        $userIP = $request->ip();
        $token = $request->input('token');
        if (!$this->checkTokenRequest($token, $userIP)) {
            // 禁止该Token请求
            header('Location: https://bilibili.com');
            exit();
        }

        if(!$this->checkUA($request)){
            header('Location: https://bilibili.com');
            exit();
        }

        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;


        $ip2region = new \Ip2Region();
        try {
            $result = $ip2region->simple($userIP);
        } catch (\Exception $e) {
            // 处理异常情况
            // 可以输出错误信息或执行其他逻辑
            $result = "未知地区";
        }


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

    //过滤 UA 白名单
    private function checkUA($request): bool
    {
        $UA = strtolower($request->header('User-Agent'));
        $allowedFlags = ['clash', 'clashforandroid', 'meta', 'shadowrocket', 'sing-box', 'SFA', 'clashforwindows', 'clash-verge', 'loon',  'quantumult', 'sagerNet', 'surge', 'v2ray', 'passwall', 'ssrplus', 'shadowsocks', 'netch'];
        $flagContainsAllowed = false;
        foreach ($allowedFlags as $allowedFlag) {
            if (strpos($UA, $allowedFlag) !== false) {
                $flagContainsAllowed = true;
                break;
            }
        }
        if (!$flagContainsAllowed) {
            return false;
        }else{
            return true;
        }
    }
    private function checkTokenRequest($token, $ip): bool
    {
        $hourAgo = time() - 3600; // 一小时前的时间
        $tokenRequest = Tokenrequest::firstOrCreate(
            ['token' => $token, 'ip' => $ip],
            ['requested_at' => time()]
        );

        $requests = Tokenrequest::where('token', $token)
            ->where('requested_at', '>', $hourAgo)
            ->distinct('ip')
            ->count('ip');

        if ($requests > 16) {
            // 禁止该Token请求
            // 可以在这里记录禁止请求的日志或执行其他逻辑
            return false;
        }

        return true;
    }


}
