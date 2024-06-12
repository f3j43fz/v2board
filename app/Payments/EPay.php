<?php

namespace App\Payments;
use App\Utils\CacheKey;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;

class EPay {
    private $config;
    private $client;

    public function __construct($config)
    {
        $this->config = $config;
        $this->client = new Client();
    }

    public function form()
    {
        return [
            'url' => [
                'label' => 'URL',
                'description' => '',
                'type' => 'input',
            ],
            'pid' => [
                'label' => 'PID',
                'description' => '',
                'type' => 'input',
            ],
            'key' => [
                'label' => 'KEY',
                'description' => '',
                'type' => 'input',
            ],
            'type' => [
                'label' => 'TYPE',
                'description' => '',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        // 默认情况下，$money 为人民币
        $money = $order['total_amount'] / 100;

        // 如果是美元，则按照汇率换算成人民币
        if(config('v2board.currency') === 'USD'){
            $rate = $this->get_usd_to_cny_rate();
            $rate = $rate ?? config('v2board.default_usd_to_cny_rate', 7.22);
            // 上浮 3 毛钱
            $money = round($money * ($rate + 0.30), 2);
        }

        $name = "使用QQ/微信很危险，如有订单问题请发邮件联系：support@v2pass.net 其他联系方式均无效！";

        $params = [
            'money' => $money,
            'name' => $name,
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'out_trade_no' => $order['trade_no'],
            'pid' => $this->config['pid']
        ];
        if (isset($this->config['type'])){
            $params['type']=$this->config['type'];
        }
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        $params['sign'] = md5($str);
        $params['sign_type'] = 'MD5';
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $this->config['url'] . '/submit.php?' . http_build_query($params)
        ];
    }

    public function notify($params)
    {
        $sign = $params['sign'];
        unset($params['sign']);
        unset($params['sign_type']);
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        if ($sign !== md5($str)) {
            return false;
        }
        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['trade_no']
        ];
    }

    private function get_usd_to_cny_rate()
    {
        $cacheKey = CacheKey::get('USD_TO_CNY_RATE', 'global');
        $rate = Cache::get($cacheKey);

        if (!$rate) {
            // 尝试从两个不同的API源获取汇率
            $urls = [
                'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json',
                'https://latest.currency-api.pages.dev/v1/currencies/usd.json'
            ];

            foreach ($urls as $url) {
                try {
                    $response = $this->client->get($url);
                    $data = json_decode($response->getBody()->getContents(), true);

                    // 检查API响应中是否包含CNY汇率
                    if (isset($data['usd']['cny'])) {
                        $rate = $data['usd']['cny'];
                        Cache::put($cacheKey, $rate, 3600); // 缓存汇率
                        break; // 成功获取到汇率后跳出循环
                    }
                } catch (GuzzleException $e) {
                    // 处理Guzzle HTTP客户端错误
                    \Log::error("Attempt to fetch from $url failed: " . $e->getMessage(), ['exception' => $e]);
                    continue; // 尝试下一个URL
                } catch (\Exception $e) {
                    // 处理其他异常
                    \Log::error("Error during rate fetching from $url: " . $e->getMessage(), ['exception' => $e]);
                    continue; // 尝试下一个URL
                }
            }

            if (!$rate) {
                \Log::error("Failed to retrieve USD to CNY rate from all sources.");
                return null; // 如果所有源都失败，则返回null
            }
        }

        return (float) $rate;
    }

}
