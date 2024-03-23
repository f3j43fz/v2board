<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\Tokenrequest;
use App\Models\User;
use App\Protocols\General;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Ip2Region;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $servers = [];
        $user = $request->user;
        $userService = new UserService();

        // UA过滤
        if(!$this->checkUA($request->header('User-Agent'))){
            return redirect('https://bilibili.com');
        }

        // 过滤封禁用户
        if ($userService->isBanned($user)){
            $response = [
                'error' => '您已被封禁'
            ];
            return response()->json($response, Response::HTTP_FORBIDDEN);
        }

        $userIP = $request->ip();
        $userID = $user->id;

        // 获取用户IP所在的地区
        $userISPInfo = $this->getUserISP($userIP);


        // 禁止多IP更新，管理员除外
//        $user = User::find($userID);
        if(!$user->is_admin){
            if (!$this->checkTokenRequest($userID, $userIP, $userISPInfo)) {
                return redirect('https://bilibili.com');
            }
        }

        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);


        if($userService->hasPlanButExpired($user) || $userService->hasPlanButExhausted($user)){
            $URL = config('v2board.app_url');
            $commonArray = [
                'type' => 'shadowsocks',
                'host' => 'baidu.com',
                'port' => 8888,
                'cipher' => 'aes-128-gcm',
            ];

            $array1 = $commonArray;
            $array1['name'] = $userService->hasPlanButExpired($user) ? "您的套餐已过期" : "您的流量已耗尽";

            $array2 = $commonArray;
            $array2['name'] = "请到： {$URL} 续费";

            $array3 = $commonArray;
            $array3['name'] = "如需帮助，可工单/邮件联系";

            // 将 $array1 和 $array2 添加到 $servers 数组中
            $servers[] = $array1;
            $servers[] = $array2;
            $servers[] = $array3;
        }else{
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            $this->setSubscribeInfoToServers($servers, $user, $userISPInfo);
        }

//        $serverService = new ServerService();
//        $servers = $serverService->getAvailableServers($user);
//        $this->setSubscribeInfoToServers($servers, $user, $userISPInfo);


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

    private function setSubscribeInfoToServers(&$servers, $user, $info)
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
    private function checkUA($UA): bool
    {
        $UA = strtolower($UA);
        $allowedFlags = ['clash', 'clashforandroid', 'meta', 'shadowrocket', 'sing-box', 'SFA', 'clashforwindows', 'clash-verge', 'hiddify', 'loon',  'quantumult', 'sagerNet', 'surge', 'v2ray', 'passwall', 'ssrplus', 'shadowsocks', 'netch'];

        foreach ($allowedFlags as $allowedFlag) {
            if (strpos($UA, $allowedFlag) !== false) {
                return true;
            }
        }

        return false;
    }




    private function checkTokenRequest($userID, $userIP, $userISPInfo): bool
    {
        $hourAgo = time() - 6 * 3600; // 6小时前的时间
        $tokenRequest = Tokenrequest::firstOrCreate(
            ['user_id' => strval($userID), 'ip' => strval($userIP)],
            ['requested_at' => time(), 'location' => $userISPInfo]
        );

        $requests = Tokenrequest::where('user_id', $userID)
            ->where('requested_at', '>', $hourAgo)
            ->distinct('ip')
            ->count('ip');

        if ($requests >= 12) {
            // 禁止该Token请求
            // 可以在这里记录禁止请求的日志或执行其他逻辑

            return false;
        }

        return true;
    }

    private function getUserISP($userIP){
        $ip2region = new \Ip2Region();
        try {
            return $ip2region->simple($userIP);
        } catch (\Exception $e) {
            // 处理异常情况
            // 可以输出错误信息或执行其他逻辑
            return "未知地区";
        }
    }
}
