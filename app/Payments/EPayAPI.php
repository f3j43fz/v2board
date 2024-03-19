<?php

namespace App\Payments;

class EPayAPI {
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
                'description' => '必填，alipay 或 wxpay',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {

        // Check if 'user_ip' key exists in the $order array
        $userIp = isset($order['user_ip']) ? $order['user_ip'] : '192.169.1.7';

        $params = [
            'money' => $order['total_amount'] / 100,
            'name' => $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'out_trade_no' => $order['trade_no'],
            'pid' => $this->config['pid'],
            'type' => $this->config['type'],
            'clientip' => $userIp
        ];

        ksort($params);
        reset($params);

        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        $params['sign'] = md5($str);
        $params['sign_type'] = 'MD5';

        $url = $this->config['url'] . '/mapi.php';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($result['code'] == 1 && isset($result['payurl'])) {
            return [
                'type' => 1, // 0:qrcode 1:url
                'data' => $result['payurl']
            ];
        } else {
            return [
                'type' => -1, // Error
                'data' => isset($result['msg']) ? $result['msg'] : 'Unknown response format'
            ];
        }
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
}
