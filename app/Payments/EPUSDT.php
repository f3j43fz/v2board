<?php

namespace App\Payments;

use GuzzleHttp\Client;

class EPUSDT {
    public function __construct($config)
    {
        $this->config = $config;
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
            ],
            'epusdt_pay_channel' => [
                'label' => 'Channel',
                'description' => '您的 EpusdtPay 支付通道(例如: trc20, polygon)',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $params = [
            'amount' => $order['total_amount'] / 100,
            'notify_url' => $order['notify_url'],
            'redirect_url' => $order['return_url'],
            'order_id' => $order['trade_no'],
            'channel' => $this->config['epusdt_pay_channel'],
        ];
        $params['signature'] = $this->epusdtSign($params, $this->config['pid']);
        $client = new Client([
            'headers' => [ 'Content-Type' => 'application/json' ]
        ]);
        $response = $client->post($this->config['url'], ['body' => json_encode($params)]);
        $body = json_decode($response->getBody()->getContents(), true);
        if (!isset($body['status_code']) || $body['status_code'] != 200) {
            abort(500, __("请求失败") . $body['message']);
        }
        return redirect()->away($body['data']['payment_url']);

    }

    public function notify($params)
    {
        $sign = $params['sign'];
        unset($params['sign']);
        unset($params['sign_type']);
        ksort($params);
        reset($params);
        $md5 = $this->epusdtSign($params, $this->config['pid']);
        if ($sign !== $md5) {
            return false;
        }
        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['trade_no']
        ];
    }

    function epusdtSign(array $parameter, string $signKey)
    {
        ksort($parameter);
        reset($parameter); //内部指针指向数组中的第一个元素
        $sign = '';
        $urls = '';
        foreach ($parameter as $key => $val) {
            if ($val == '') continue;
            if ($key != 'signature') {
                if ($sign != '') {
                    $sign .= "&";
                    $urls .= "&";
                }
                $sign .= "$key=$val"; //拼接为url参数形式
                $urls .= "$key=" . urlencode($val); //拼接为url参数形式
            }
        }
        return md5($sign . $signKey); //密码追加进入开始MD5签名
    }
}
