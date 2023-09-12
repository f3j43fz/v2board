<?php

namespace App\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redirect;

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
            'epusdt_pay_channel' => [
                'label' => 'Channel',
                'description' => 'epusdt 支付通道(2选1: trc20, polygon)',
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

        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post($this->config['url'], $params);
        $body = $response->json();
        if (!isset($body['status_code']) || $body['status_code'] != 200) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response(__("请求失败") . $body['message'], 500));
        }
        return Redirect::away($body['data']['payment_url']);
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
            return 'fail';
        }
        $sign = $params['signature'];
        unset($params['signature']);
        $md5 = $this->epusdtSign($params, $this->config['pid']);
        if ($sign !== $md5) {
            return 'fail';
        }
        return 'ok';
    }
}
