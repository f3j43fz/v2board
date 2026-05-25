<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use ReCaptcha\ReCaptcha;

use function PHPUnit\Framework\isEmpty;

use Illuminate\Support\Facades\Http;

class CommController extends Controller
{
    private function isEmailVerify()
    {
        return response([
            'data' => (int)config('v2board.email_verify', 0) ? 1 : 0
        ]);
    }

    public function sendEmailVerify(CommSendEmailVerify $request)
    {
        $userIP = $request->ip();

        if ((int)config('v2board.recaptcha_enable', 0)) {

            $secret = config('v2board.recaptcha_key');

            $response = $this->antiXss->xss_clean($request->input('recaptcha_data'));

            $response = Http::post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret,
                'response' => $response,
                'ip' => $userIP,
            ]);

            if ($response->failed()) {
                abort(500, __('Failed to verify captcha'));
            }

            $responseData = $response->json();

            if (isset($responseData['success']) && $responseData['success'] === true) {
                // Verification successful
            } else {
                abort(500, __('Invalid code is incorrect'));
            }
        }

        $email = $this->antiXss->xss_clean($request->input('email'));

        $isforget = $request->input('isforget');
        $email_exists = User::where('email', $email)->exists();
        // 决定是否真正发邮件；不发也返回 200，防止账号枚举（注册/找回的 oracle 失效）
        $shouldSend = true;
        if (isset($isforget)) {
            if ($isforget == 0 && $email_exists) $shouldSend = false;  // 注册场景：邮箱已注册 → 静默跳过
            if ($isforget == 1 && !$email_exists) $shouldSend = false; // 找回场景：邮箱不存在 → 静默跳过
        }

        // 防邮件轰炸/spam relay：按 IP 限速（不论是否真发，先扣 IP 配额，防枚举者用"无效邮箱"绕过限速）
        $clientIp = $request->ip();
        if ($clientIp) {
            if (Cache::get(CacheKey::get('EMAIL_VERIFY_IP_RATE_LIMIT', $clientIp))) {
                abort(500, __('Email verification code has been sent, please request again later'));
            }
            $dailyKey = CacheKey::get('EMAIL_VERIFY_IP_DAILY_COUNT', $clientIp);
            $dailyCount = (int)Cache::get($dailyKey, 0);
            if ($dailyCount >= 20) {
                abort(500, __('Email verification code has been sent, please request again later'));
            }
            // 即使后面不真发邮件，也立即记一次 IP 配额
            Cache::put(CacheKey::get('EMAIL_VERIFY_IP_RATE_LIMIT', $clientIp), time(), 10);
            Cache::put($dailyKey, $dailyCount + 1, 86400);
        }

        // 静默成功（攻击者无法通过响应区分邮箱是否注册）
        if (!$shouldSend) {
            return response(['data' => true]);
        }

        // 真发邮件路径
        if (Cache::get(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email))) {
            abort(500, __('Email verification code has been sent, please request again later'));
        }
        $code = random_int(100000, 999999);
        $subject = '您的'. config('v2board.app_name', 'V2Board') . __('Email verification code') . '： ' . $code;
        $userName = explode('@', $email)[0];
        SendEmailJob::dispatch([
            'email' => $email,
            'subject' => $subject,
            'template_name' => 'verify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'code' => $code,
                'url' => config('v2board.app_url'),
                'userName' => $userName
            ]
        ]);

        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), $code, 300);
        Cache::put(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email), time(), 60);
        // 注意：IP 配额在函数前部已写入，不在此处重复
        return response([
            'data' => true
        ]);
    }

    public function pv(Request $request)
    {
        // 每 IP 每日最多 100 次 pv，防止脚本污染邀请码统计（throttle:10/min/IP 是同步保护，这里加日上限做纵深防御）
        $clientIp = $request->ip();
        if ($clientIp) {
            $dailyKey = CacheKey::get('PV_DAILY_LIMIT_IP', $clientIp);
            $dailyCount = (int)Cache::get($dailyKey, 0);
            if ($dailyCount >= 100) {
                // 静默丢弃，不告知攻击者上限存在
                return response(['data' => true]);
            }
            Cache::put($dailyKey, $dailyCount + 1, 86400);
        }

        $invite_code = $this->antiXss->xss_clean($request->input('invite_code'));
        $inviteCode = InviteCode::where('code', $invite_code)->first();
        if ($inviteCode) {
            $inviteCode->pv = $inviteCode->pv + 1;
            $inviteCode->save();
        }

        return response([
            'data' => true
        ]);
    }

    private function getEmailSuffix()
    {
        $suffix = config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT);
        if (!is_array($suffix)) {
            return preg_split('/,/', $suffix);
        }
        return $suffix;
    }
}
