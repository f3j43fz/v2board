<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use ReCaptcha\ReCaptcha;
use Agent;
use foroco\BrowserDetection;
use Illuminate\Support\Facades\Http;

class AuthController extends Controller
{
    public function loginWithMailLink(Request $request)
    {
        if (!(int)config('v2board.login_with_mail_link_enable')) {
            abort(404);
        }
        $params = $request->validate([
            'email' => 'required|email:strict',
            'redirect' => 'nullable'
        ]);

        if (Cache::get(CacheKey::get('LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP', $params['email']))) {
            abort(500, __('Sending frequently, please try again later'));
        }

        $user = User::where('email', $params['email'])->first();
        if (!$user) {
            return response([
                'data' => true
            ]);
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 300);
        Cache::put(CacheKey::get('LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP', $params['email']), time(), 60);


        $redirect = '/#/login?verify=' . $code . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
        if (config('v2board.app_url')) {
            $link = config('v2board.app_url') . $redirect;
        } else {
            $link = url($redirect);
        }

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('Login to :name', [
                'name' => config('v2board.app_name', 'V2Board')
            ]),
            'template_name' => 'login',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'link' => $link,
                'url' => config('v2board.app_url')
            ]
        ]);

        return response([
            'data' => $link
        ]);

    }

    public function register(AuthRegister $request)
    {
        $userIP = $request->ip();

        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            if ((int)$registerCountByIP >= (int)config('v2board.register_limit_count', 3)) {
                abort(500, __('Register frequently, please try again after :minute minute', [
                    'minute' => config('v2board.register_limit_expire', 60)
                ]));
            }
        }
//        if ((int)config('v2board.recaptcha_enable', 0)) {
//            $recaptcha = new ReCaptcha(config('v2board.recaptcha_key'));
//            $recaptchaResp = $recaptcha->verify($request->input('recaptcha_data'));
//            if (!$recaptchaResp->isSuccess()) {
//                abort(500, __('Invalid code is incorrect'));
//            }
//        }

        if ((int)config('v2board.recaptcha_enable', 0)) {

            $secret = config('v2board.recaptcha_key');
            $response = $request->input('recaptcha_data');

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

        if ((int)config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $request->input('email'),
                config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))
            ) {
                abort(500, __('Email suffix is not in the Whitelist'));
            }
        }
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $request->input('email'))[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                abort(500, __('Gmail alias is not supported'));
            }
        }
        if ((int)config('v2board.stop_register', 0)) {
            abort(500, __('Registration has closed'));
        }
        if ((int)config('v2board.invite_force', 0)) {
            if (empty($request->input('invite_code'))) {
                abort(500, __('You must use the invitation code to register'));
            }
        }
        if ((int)config('v2board.email_verify', 0)) {
            if (empty($request->input('email_code'))) {
                abort(500, __('Email verification code cannot be empty'));
            }
            if ((string)Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email'))) !== (string)$request->input('email_code')) {
                abort(500, __('Incorrect email verification code'));
            }
        }
        $email = $request->input('email');
        $password = $request->input('password');
        $exist = User::where('email', $email)->first();
        if ($exist) {
            abort(500, __('Email already exists'));
        }
        $user = new User();
        $user->email = $email;
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if ($request->input('invite_code')) {
            $inviteCode = InviteCode::where('code', $request->input('invite_code'))
                ->where('status', 0)
                ->first();
            if (!$inviteCode) {
                if ((int)config('v2board.invite_force', 0)) {
                    abort(500, __('Invalid invitation code'));
                }
            } else {
                $user->invite_user_id = $inviteCode->user_id ? $inviteCode->user_id : null;
                if (!(int)config('v2board.invite_never_expire', 0)) {
                    $inviteCode->status = 1;
                    $inviteCode->save();
                }
            }
        }

        // try out
        if ((int)config('v2board.try_out_plan_id', 0)) {
            $plan = Plan::find(config('v2board.try_out_plan_id'));
            if ($plan) {
                $user->transfer_enable = $plan->transfer_enable * 1073741824;
                $user->plan_id = $plan->id;
                $user->group_id = $plan->group_id;
                $user->expired_at = time() + (config('v2board.try_out_hour', 1) * 3600);
                $user->speed_limit = $plan->speed_limit;
            }
        }

        if (!$user->save()) {
            abort(500, __('Register failed'));
        }
        if ((int)config('v2board.email_verify', 0)) {
            Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email')));
        }

        $user->last_login_at = time();
        $user->save();

        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            Cache::put(
                CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip()),
                (int)$registerCountByIP + 1,
                (int)config('v2board.register_limit_expire', 60) * 60
            );
        }

        $authService = new AuthService($user);

        return response()->json([
            'data' => $authService->generateAuthData($request)
        ]);
    }

    public function login(AuthLogin $request)
    {

        $email = $request->input('email');
        $password = $request->input('password');

        if ((int)config('v2board.password_limit_enable', 1)) {
            $passwordErrorCount = (int)Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
            if ($passwordErrorCount >= (int)config('v2board.password_limit_count', 5)) {
                abort(500, __('There are too many password errors, please try again after :minute minutes.', [
                    'minute' => config('v2board.password_limit_expire', 60)
                ]));
            }
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            abort(500, __('Incorrect email or password'));
        }
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $password,
            $user->password)
        ) {
            if ((int)config('v2board.password_limit_enable')) {
                Cache::put(
                    CacheKey::get('PASSWORD_ERROR_LIMIT', $email),
                    (int)$passwordErrorCount + 1,
                    60 * (int)config('v2board.password_limit_expire', 60)
                );
            }
            abort(500, __('Incorrect email or password'));
        }

        if ($user->banned) {
            abort(500, __('Your account has been suspended'));
        }


         //登录提醒逻辑
        if(!$user->is_admin){
            $userIP = $this->maskIpAddress($request->ip());
            $userISPInfo = $this->getUserISP($request->ip());

            // 方式一
            $device = Agent::device(); // 使用 Agent Facade 调用设备信息
//            $browser = Agent::browser();
//            $browserVersion = Agent::version($browser);
//            $platform = Agent::platform();
//            $platformVersion = Agent::version($platform);


            //方式二
            $browserDetection = new BrowserDetection();
            $userAgent = $request->header('User-Agent');
            // 提取操作系统、浏览器和版本信息
            $result = $browserDetection->getAll($userAgent);
            $browser = $result['browser_name'];
            $browserVersion = $result['browser_version'];
            $platform = $result['os_name'];
            $platformVersion = $result['os_version'];

            //获取邮箱用户名 以及 时间
            $userName = explode('@', $user->email)[0];
            $now = time();
            $datetime = date("Y-m-d H:i:s", $now);

            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => __('You are logging into :app_name', [
                    'app_name' => config('v2board.app_name', 'V2board')
                ]),
                'template_name' => 'remindLogin',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'userName' => $userName,
                    'datetime' => $datetime,
                    'url' => config('v2board.app_url'),
                    'ip' => $userIP,
                    'ipInfo' => $userISPInfo,
                    'device' => $device,
                    'platform' => $platform,
                    'platformVersion' => $platformVersion,
                    'browser' => $browser,
                    'browserVersion' => $browserVersion
                ]
            ]);


            $user->last_login_at = time();
            $user->last_login_ip = $request->ip();
            $user->save();

        }

        $authService = new AuthService($user);
        return response([
            'data' => $authService->generateAuthData($request)
        ]);
    }

    public function token2Login(Request $request)
    {
        if ($request->input('token')) {
            $redirect = '/#/login?verify=' . $request->input('token') . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
            if (config('v2board.app_url')) {
                $location = config('v2board.app_url') . $redirect;
            } else {
                $location = url($redirect);
            }
            return redirect()->to($location)->send();
        }

        if ($request->input('verify')) {
            $key =  CacheKey::get('TEMP_TOKEN', $request->input('verify'));
            $userId = Cache::get($key);
            if (!$userId) {
                abort(500, __('Token error'));
            }
            $user = User::find($userId);
            if (!$user) {
                abort(500, __('The user does not '));
            }
            if ($user->banned) {
                abort(500, __('Your account has been suspended'));
            }
            Cache::forget($key);
            $authService = new AuthService($user);
            return response([
                'data' => $authService->generateAuthData($request)
            ]);
        }
    }

    public function getQuickLoginUrl(Request $request)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (!$authorization) abort(403, '未登录或登陆已过期');

        $user = AuthService::decryptAuthData($authorization);
        if (!$user) abort(403, '未登录或登陆已过期');

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user['id'], 60);
        $redirect = '/#/login?verify=' . $code . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
        if (config('v2board.app_url')) {
            $url = config('v2board.app_url') . $redirect;
        } else {
            $url = url($redirect);
        }
        return response([
            'data' => $url
        ]);
    }

    public function forget(AuthForget $request)
    {
        $forgetRequestLimitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $request->input('email'));
        $forgetRequestLimit = (int)Cache::get($forgetRequestLimitKey);
        if ($forgetRequestLimit >= 3) abort(500, __('Reset failed, Please try again later'));
        if ((string)Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email'))) !== (string)$request->input('email_code')) {
            Cache::put($forgetRequestLimitKey, $forgetRequestLimit ? $forgetRequestLimit + 1 : 1, 300);
            abort(500, __('Incorrect email verification code'));
        }
        $user = User::where('email', $request->input('email'))->first();
        if (!$user) {
            abort(500, __('This email is not registered in the system'));
        }
        $user->password = password_hash($request->input('password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;
        if (!$user->save()) {
            abort(500, __('Reset failed'));
        }
        Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email')));
        return response([
            'data' => true
        ]);
    }

    private function getUserISP($userIP){
        $ip2region = new \Ip2Region();
        try {
            return $ip2region->simple($userIP);
        } catch (\Exception $e) {
            // 处理异常情况
            // 可以输出错误信息或执行其他逻辑
            return "未知地区";
        }
    }

    private function expandIPv6Address($ip)
    {
        // Check if the input is a valid IPv6 address
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        // Split the IPv6 address into groups
        $groups = explode(':', $ip);

        // Count the number of groups
        $groupCount = count($groups);

        // Find the index of the compressed group (::)
        $compressedGroupIndex = array_search('', $groups);

        // Calculate the number of groups needed to expand the address
        $expandedGroupCount = 8 - $groupCount;

        // Expand the compressed group (::) with zeros
        if ($compressedGroupIndex !== false) {
            $expandedGroups = array_merge(
                array_slice($groups, 0, $compressedGroupIndex),
                array_fill(0, $expandedGroupCount, '0000'),
                array_slice($groups, $compressedGroupIndex + 1)
            );
        } else {
            $expandedGroups = $groups;
        }

        // Pad each group with zeros to ensure four hexadecimal digits
        $expandedGroups = array_map(function ($group) {
            return str_pad($group, 4, '0', STR_PAD_LEFT);
        }, $expandedGroups);

        // Join the expanded groups and format the IPv6 address
        $expandedIp = implode(':', $expandedGroups);

        return $expandedIp;
    }

    private function maskIpAddress($ipAddress)
    {
        // Check if the IP address is IPv4
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ipAddress);
            $parts[1] = '*';
            $parts[2] = '*';
            return implode('.', $parts);
        }

        // expand ipv6
        $ipv6 = $this->expandIPv6Address($ipAddress);
        $parts = explode(':', $ipv6);
        $parts[count($parts) - 4] = '*';
        $parts[count($parts) - 3] = '*';
        $parts[count($parts) - 2] = '*';
        $parts[count($parts) - 1] = '*';
        return implode(':', $parts);
    }
}
