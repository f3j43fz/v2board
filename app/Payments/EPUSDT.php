<?php

namespace App\Payments;

use GuzzleHttp\Client;

class EPUSDT {

    private $config;

    public function __construct($config)
    {
        $this->config = $config;
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
        $params = [
            'amount' => $order['total_amount'] / 100,
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
        $urls = '';
        foreach ($parameter as $key => $val) {
            if ($val == '') continue;
            if ($key != 'signature') {
                if ($sign != '') {
                    $sign .= "&";
                    $urls .= "&";
                }
                $sign .= "$key=$val"; //拼接为url参数形式
            }
        }
        return md5($sign . $signKey); //密码追加进入开始MD5签名
    }

    public function notify($params)
    {
        $status = $params['status'];
        // 1：等待支付，2：支付成功，3：已过期
        if ($status != 2) {
            return false;
        }
        $sign = $params['signature'];
        unset($params['signature']);
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
}
