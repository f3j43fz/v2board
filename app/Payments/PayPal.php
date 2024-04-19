<?php

namespace App\Payments;

use App\Models\Config;
use App\Services\Exchange;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class PayPal {
    private $config;
    private $client;

    public function __construct($config) {
        $this->config = $config;
        $this->client = new Client([
            'headers' => ['Content-Type' => 'application/json']
        ]);
    }

    public function form() {
        return [
            'mode' => [
                'label' => 'mode',
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
        $accessToken = $this->getAccessToken();
        $cnyMoney = $order['total_amount'] / 100;
        $to = $this->config['currency'];
        $exchange_amount = ($this->exchange($cnyMoney, 'CNY', $to));

        $params = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'amount' => [
                        'currency_code' => $this->config['currency'],
                        'value' => number_format( $exchange_amount, 2, '.', ''),
                    ],
                    'reference_id' => $order['trade_no'],
                ],
            ]];

        try {
            $response = $this->client->post($this->getApiUrl() . '/v2/checkout/orders', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode($params)
            ]);
            $body = json_decode($response->getBody()->getContents(), true);

            $approvalUrl = null;
            foreach ($body['links'] as $link) {
                if ($link['rel'] === 'approve') {
                    $approvalUrl = $link['href'];
                    break;
                }
            }

            if ($approvalUrl) {
                return [
                    'type' => 1, // 0 for QR code, 1 for URL
                    'data' => $approvalUrl
                ];
            } else {
                throw new \Exception('支付链接未找到');
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            \Log::error('Request failed: ' . $e->getMessage(), [
                'response' => $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response'
            ]);
            abort(500, "支付请求失败，请稍后再试。");  // 向用户返回通用错误消息
        }
    }



    private function getApiUrl() {
        return $this->config['mode'] === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    public function notify($params) {

        try {
            $accessToken = $this->getAccessToken();
            $orderId = $params['order_id']; // 确保order_id已正确传入

            $response = $this->client->get($this->getApiUrl() . '/v2/checkout/orders/' . $orderId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken
                ]
            ]);
            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['status']) && $body['status'] == 'COMPLETED') {
                return [
                    'trade_no' => $body['id'],
                    'callback_no' => $params['trade_no'], // 注意这里要用正确的交易号字段
                    'custom_result' => 'ok'
                ];
            } else {
                \Log::error('PayPal notify call failed.', [
                    'response' => $body
                ]);
                abort(500, "支付状态未完成或验证失败。");
            }
        } catch (\Exception $e) {
            \Log::error('Error in PayPal notify: ' . $e->getMessage(), ['exception' => $e]);
            abort(500, "网关通知处理失败。请联系管理员。");
        }
    }


    private function getAccessToken() {
        // Basic Auth Credentials
        $credentials = base64_encode($this->config['client_id'] . ':' . $this->config['client_secret']);

        try {
            $response = $this->client->post($this->getApiUrl() . '/v1/oauth2/token', [
                'headers' => [
                    'Authorization' => 'Basic ' . $credentials,
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'body' => 'grant_type=client_credentials'
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            return $body['access_token'];
        } catch (\Exception $e) {
            abort(500, "获取PayPal访问令牌失败：" . $e->getMessage());
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
