<?php

namespace App\Payments;

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
                'description' => 'Currency code (e.g., USD CNY EUR JPY)',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order) {
        $accessToken = $this->getAccessToken();
        $params = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'amount' => [
                        'currency_code' => $this->config['currency'],
                        'value' => number_format($order['total_amount'] / 100, 2, '.', ''),
                    ],
                    'reference_id' => $order['trade_no'],
                ],
            ]];

        try {
            $response = $this->client->post($this->getApiUrl() . '/v2/checkout/orders', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken
                ],
                'body' => json_encode($params)
            ]);
            $body = json_decode($response->getBody()->getContents(), true);

            $approvalUrl = null;
            foreach ($body['links'] as $link) {
                if ($link['rel'] === 'payer-action') {
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
                throw new \Exception('支付连接未找到');
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage(), ['exception' => $e]); // 记录详细的错误日志
            abort(500, "支付请求失败，请稍后再试。");  // 向用户返回通用错误消息
        }
    }



    private function getApiUrl() {
        return $this->config['mode'] === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    public function notify($params) {
        $accessToken = $this->getAccessToken();
        $orderId = $params['order_id']; // 确保order_id已正确传入

        try {
            $response = $this->client->get($this->getApiUrl() . '/v2/checkout/orders/' . $orderId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken
                ]
            ]);
            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['status']) && $body['status'] == 'COMPLETED') {
                return [
                    'trade_no' => $body['id'],
                    'callback_no' => $params['reference_id'],
                    'custom_result' => 'ok'
                ];
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage(), ['exception' => $e]); // 记录详细的错误日志
            abort(500, "网关通知处理失败。请联系管理员。"); // 向用户返回通用错误消息
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

}
