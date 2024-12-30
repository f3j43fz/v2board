<?php

namespace App\Payments;

use App\Utils\CacheKey;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;

class EPUSDT {

    private $config;

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
                'description' => 'epusdt收银台地址+/api/v1/order/create-transaction 如：http://127.0.0.1:8000/api/v1/order/create-transaction',
                'type' => 'input',
            ],
            'pid' => [
                'label' => 'PID',
                'description' => 'api接口认证token',
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
            $money = round($money * 7.3, 4);
        }

        $params = [
            'amount' => $money,
            'notify_url' => $order['notify_url'],
            'redirect_url' => $order['return_url'],
            'order_id' => $order['trade_no']
        ];
        $params['signature'] = $this->epusdtSign($params, $this->config['pid']);

        $client = new Client([
            'headers' => [ 'Content-Type' => 'application/json' ]
        ]);
        $response = $client->post($this->config['url'], ['body' => json_encode($params)]);
        $body = json_decode($response->getBody()->getContents(), true);
        if (!isset($body['status_code']) || $body['status_code'] != 200) {
            abort(500, ("请求失败") . $body['message']);
        }
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $body['data']['payment_url']
        ];
    }

    private function epusdtSign(array $parameter, string $signKey)
    {
        ksort($parameter);
        reset($parameter); //内部指针指向数组中的第一个元素
        $sign = '';
        foreach ($parameter as $key => $val) {
            if ($val == '') continue;
            if ($key != 'signature') {
                if ($sign != '') {
                    $sign .= "&";
                }
                $sign .= "$key=$val"; //拼接为url参数形式
            }
        }
        return md5($sign . $signKey); //密码追加进入开始MD5签名
    }

    public function notify($params)
    {
        $sign = $params['signature'];
        $md5 = $this->epusdtSign($params, $this->config['pid']);
        if ($sign !== $md5) {
            return false;
        }
        return [
            'trade_no' => $params['order_id'],
            'callback_no' => $params['trade_id'],
            'custom_result' => 'ok'
        ];
    }


    private function get_usd_to_cny_rate()
    {
        $cacheKey = CacheKey::get('USD_TO_CNY_RATE', 'global');
        $rate = Cache::get($cacheKey);

        if (!$rate) {
            $url = 'https://www.okx.com/v3/c2c/tradingOrders/books?quoteCurrency=CNY&baseCurrency=USDT&side=sell&paymentMethod=aliPay&userType=all&receivingAds=false&quoteMinAmountPerOrder=100&t=' . time();

            try {
                $response = $this->client->get($url);
                $data = json_decode($response->getBody()->getContents(), true);

                // 获取第一个卖家的汇率价格 (sell-0)
                if (isset($data['data']['sell'][0]['price'])) {
                    $rate = $data['data']['sell'][0]['price'];
                    Cache::put($cacheKey, $rate, 60); // 缓存汇率
                } else {
                    \Log::error("Failed to retrieve USD to CNY rate from the API response.");
                    return null;
                }
            } catch (GuzzleException $e) {
                \Log::error("Attempt to fetch from $url failed: " . $e->getMessage(), ['exception' => $e]);
                return null;
            } catch (\Exception $e) {
                \Log::error("Error during rate fetching from $url: " . $e->getMessage(), ['exception' => $e]);
                return null;
            }
        }

        return (float) $rate;
    }
}
