<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\Tokenrequest;
use App\Protocols\General;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Ip2Region;
use GeoIp2\Database\Reader;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {

        $client_ip = $request->ip();
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $client_ip = trim($ips[0]);  // 获取列表中的第一个 IP 地址
        }

        if (!filter_var($client_ip, FILTER_VALIDATE_IP)) {
            $response = [
                'error' => '非法IP地址'
            ];
            return response()->json($response, Response::HTTP_FORBIDDEN);
        }

        $servers = [];
        $user = $request->user;
        $userService = new UserService();

        // UA过滤
        $ua = $this->antiXss->xss_clean($request->header('User-Agent'));
        if(!$this->checkUA($ua)){
            return redirect('https://bilibili.com');
        }

        // 过滤封禁用户
        if ($userService->isBanned($user)){
            $response = [
                'error' => '您已被封禁'
            ];
            return response()->json($response, Response::HTTP_FORBIDDEN);
        }

        $userIP = $client_ip;
        $userID = $user->id;

        // 获取用户IP所在的地区
        $userISPInfo = $this->getUserISP($userIP);


        // 禁止多IP更新，管理员除外
        if(!$user->is_admin){
            if (!$this->checkTokenRequest($userID, $userIP, $userISPInfo)) {
                $response = [
                    'error' => '您的请求IP过多，已暂时禁止您更新订阅'
                ];
                return response()->json($response, Response::HTTP_FORBIDDEN);
            }
        }

        //优先识别 flag 然后识别 UA
        $flag = $this->antiXss->xss_clean($request->input('flag'))
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);

        // 检查 4 种情况： 【按周期】套餐过期、【按流量】套餐满流量、【随用随付】套餐没余额、小火箭版本太低，用不了直连套餐
        // 小火箭版本 2.2.30(1947)

        $hasDirectPlan = $userService->hasDirectPlan($user);

        $URL = config('v2board.app_url');
        $commonArray = [
            'type' => 'shadowsocks',
            'host' => 'baidu.com',
            'port' => 8888,
            'cipher' => 'aes-128-gcm',
        ];

        $array1 = $commonArray;
        $array2 = $commonArray;
        $array3 = $commonArray;

        if($userService->hasPlanButExpired($user)){
            $array1['name'] = "您的套餐已过期";
            $array2['name'] = "请到： {$URL} 续费";
            $array3['name'] = "如需帮助，可工单/邮件联系";

            $servers[] = $array1;
            $servers[] = $array2;
            $servers[] = $array3;
        }elseif ($userService->hasPlanButExhausted($user)){
            $array1['name'] = "您的流量已耗尽";
            $array2['name'] = "请到： {$URL} 续费";
            $array3['name'] = "如需帮助，可工单/邮件联系";

            $servers[] = $array1;
            $servers[] = $array2;
            $servers[] = $array3;
        }elseif (($user->is_PAGO == 1 && $user->balance == 0)){
            $array1['name'] = "您的余额不足";
            $array2['name'] = "请到： {$URL} 充值";
            $array3['name'] = "如需帮助，可工单/邮件联系";

            $servers[] = $array1;
            $servers[] = $array2;
            $servers[] = $array3;
        }elseif ($hasDirectPlan && strpos($flag, 'shadowrocket') && $this->extractShadowrocketVersion($flag) < 1947){
            $array1['name'] = "您的小火箭本版不支持直连套餐";
            $array2['name'] = "请更新小火箭";
            $array3['name'] = "然后再更新订阅";

            $servers[] = $array1;
            $servers[] = $array2;
            $servers[] = $array3;
        }elseif ($hasDirectPlan && !$this->supportRalityAndHisteria2($flag) ){
            $array1['name'] = "本客户端不支持直连套餐";
            $array2['name'] = "请您下载其他客户端";
            $array3['name'] = "然后在新的客户端导入订阅";

            $servers[] = $array1;
            $servers[] = $array2;
            $servers[] = $array3;
        } else{
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            $this->setSubscribeInfoToServers($servers, $user, $userISPInfo);
        }



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

        $rate=config('v2board.invite_commission');

        array_unshift($servers, array_merge($servers[0], [
            'name' => "💰 邀请好友得 {$rate} % 佣金 ",
        ]));

        array_unshift($servers, array_merge($servers[0], [
            'name' => "⏳ 套餐到期：{$expiredDate}",
        ]));

        if($user->expired_at !== NULL){
            $expireMonth = date('m', $user->expired_at);
            $currentMonth = date('m');
            if ($expireMonth != $currentMonth) {
                if ($resetDay) {
                    array_unshift($servers, array_merge($servers[0], [
                        'name' => "🔄 距离下次重置剩余：{$resetDay} 天",
                    ]));
                }
            }
        }

        array_unshift($servers, array_merge($servers[0], [
            'name' => "📶 您的网络：{$info} ",
        ]));
    }


    //过滤 UA 白名单
    private function checkUA($UA): bool
    {
        $UA = strtolower($UA);
        $allowedFlags = ['clash', 'clashforandroid', 'meta', 'shadowrocket', 'sing-box', 'SFA', 'clashforwindows', 'clash-verge', 'hiddify', 'loon',  'quantumult', 'sagerNet', 'surge', 'v2ray', 'passwall', 'ssrplus', 'shadowsocks', 'netch', 'nyanpasu', 'streisand'];

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


//        private function getUserISP($userIP){
//
//        // 中国IP ，则优先掉用 Ip2Region 库
//        if($this->isFromChina($userIP)){
//            // 大多数情况 ipv4 以及少量 ipv6
//            $ip2region = new \Ip2Region();
//            try {
//                $text =  $ip2region->simple($userIP);
//                // 检查字符串中是否包含“中国”二字
//                if (strpos($text, "中国") !== false) {
//                    // 如果包含，则去掉“中国”二字
//                    $text = str_replace("中国", "", $text);
//                }
//                return $text;
//            } catch (\Exception $e) {
//                // 查不到的情况，主要是 ipv6，调用在线 API
//                return $this->getUserISPException($userIP);
//            }
//        } else {
//            // 国外ip，调用在线 API
//            return $this->getUserISPException($userIP);
//        }
//
//    }

    private function getUserISP($userIP): string
    {
        // 美图API的URL
        $apiUrl = "https://webapi-pc.meitu.com/common/ip_location?ip={$userIP}";

        // 使用GuzzleHttp或其他HTTP库进行GET请求
        $client = new Client();

        try {
            // 发起请求
            $response = $client->request('GET', $apiUrl);
            $responseBody = json_decode($response->getBody(), true);

            // 检查请求结果
            if ($responseBody['code'] === 0) {
                // 解析返回的数据
                $ipData = reset($responseBody['data']); // 获取第一个元素的数据

                // 判断国家代码是否为中国
                if (isset($ipData['nation_code']) && $ipData['nation_code'] === 'CN') {
                    // 拼接省份、城市和ISP信息
                    $province = $ipData['province'] ?? '';
                    $city = $ipData['subdivision_2_name'] ?? $ipData['city'] ?? ''; // subdivision_2_name 或 city
                    $isp = $ipData['isp'] ?? '';

                    return "{$province}{$city}{$isp}";
                } else {
                    // 如果国家代码不是中国，调用备用方法
                    return $this->getUserISPOutsideChina($userIP);
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
    private function getUserISPOutsideChina($userIP): string
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

    private function extractShadowrocketVersion($ua) {
        $pattern = '/Shadowrocket\/(\d+)/';
        preg_match($pattern, $ua, $matches);
        return $matches[1] ?? '未知版本';
    }

    private function supportRalityAndHisteria2($flag) {
        $keywords = ['verge', 'meta', 'nyanpasu', 'hiddify', 'sing', 'passwall', 'shadowrocket', 'streisand'];
        foreach ($keywords as $keyword) {
            if (strpos($flag, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

}
