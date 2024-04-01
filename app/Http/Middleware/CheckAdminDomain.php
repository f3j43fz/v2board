<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckAdminDomain
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // 从配置中获取允许访问管理员端的域名字符串，并转换为数组
        $allowedDomains = explode(',', config('v2board.admin_safe_domain'));

        // 去除域名中的空格，并转换为小写
        $allowedDomains = array_map('trim', $allowedDomains);
        $allowedDomains = array_map('strtolower', $allowedDomains);

        // 获取当前请求的域名并转换为小写
        $currentDomain = strtolower($request->getHost());

        // 检查当前请求的域名是否在允许的域名数组中
        if (!in_array($currentDomain, $allowedDomains)) {
            // 如果不在允许的域名数组中，返回错误响应或重定向
            abort(403, '此域名无权访问管理员端');
        }

        return $next($request);
    }

}
