<?php

namespace App\Payments;

use Srmklive\PayPal\Services\PayPal as PayPalClient;

class PayPal {
    private $config;

    public function __construct($config) {
        $this->config = $config;
    }

    public function form() {
        return [
            'mode' => [
                'label' => 'Mode',
                'description' => '沙箱/生产模式  sandbox/live',
                'type' => 'input',
            ],
            'client_id' => [
                'label' => 'Client ID',
                'description' => 'PayPal Client ID',
                'type' => 'input',
            ],
            'client_secret' => [
                'label' => 'Client Secret',
                'description' => 'PayPal Client Secret',
                'type' => 'input',
            ],
            'currency' => [
                'label' => 'Currency',
                'description' => '结算货币代码 (e.g., USD EUR JPY) 系统自动将人民币换成对应的货币，建议填 USD',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order) {

        $config = [
            'mode' => $this->config['mode'] == 'live' ? 'live' : 'sandbox',
            'sandbox' => [
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'app_id' => '',
            ],
            'live' => [
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'app_id' => '',
            ],
            'payment_action' => 'Sale',
            'currency' => $this->config['currency'],
            'notify_url' => '',
            'locale' => 'en_US',
            'validate_ssl' => true,
        ];


        $cnyMoney = $order['total_amount'] / 100;
        $to = $this->config['currency'];
        $trade_no = $order['trade_no'];
        $exchange_amount = $this->exchange($cnyMoney, 'CNY', $to);

        $order_data = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'amount' => [
                        'currency_code' => $to,
                        'value' => $exchange_amount,
                    ],
                    'reference_id' => $trade_no,
                ],
            ],
        ];

        $pp = new PayPalClient($config);
        $pp->getAccessToken();
        $order = $pp->createOrder($order_data);

        \Log::info($order); // 记录详细的错误日志

        $approvalUrl = "";
        foreach ($order['links'] as $link) {
            if ($link['rel'] === 'approve') {
                $approvalUrl = $link['href'];
                break;
            }
        }

        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $approvalUrl
        ];

    }

    public function notify($params) {

        $config = [
            'mode' => $this->config['mode'] == 'live' ? 'live' : 'sandbox',
            'sandbox' => [
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'app_id' => '',
            ],
            'live' => [
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'app_id' => '',
            ],
            'payment_action' => 'Sale',
            'currency' => $this->config['currency'],
            'notify_url' => '',
            'locale' => 'en_US',
            'validate_ssl' => true,
        ];

        $order_id = $params['order_id'];

        $pp = new PayPalClient($config);
        $pp->getAccessToken();

        $result = $pp->capturePaymentOrder($order_id);

        if (isset($result['status']) && $result['status'] === 'COMPLETED') {
            return [
                'trade_no' => $params['order_id'],
                'callback_no' => $params['order_id'],
                'custom_result' => 'ok'
            ];
        }
    }


    public function exchange(float $amount, string $from, string $to): float
    {
        return round($amount *  $this->getExchangeRate($from, $to), 2);
    }

    private function getExchangeRate(string $from, string $to): float
    {
        $response = $this->client->get('https://cdn.moneyconvert.net/api/latest.json');
        $data = json_decode($response->getBody()->getContents(), true);
        $rate = $data['rates'][$to] / $data['rates'][$from];
        return (float) $rate;
    }
}
