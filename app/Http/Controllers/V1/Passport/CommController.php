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
        if (isset($isforget)) {
            // 重复注册
            if ($isforget == 0 && $email_exists) {
                abort(500, __('Fuck you'));
            }
            // 忘记密码，恶意刷验证码
            if ($isforget == 1 && !$email_exists) {
                abort(500, __('Fuck you'));
            }
        }

        if (Cache::get(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email))) {
            abort(500, __('Email verification code has been sent, please request again later'));
        }
        $code = rand(100000, 999999);
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
        return response([
            'data' => true
        ]);
    }

    public function pv(Request $request)
    {
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
