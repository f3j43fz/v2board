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
        // 设置允许访问管理员端的域名
        $allowedDomain = 'a.net';

        // 检查当前请求的域名是否与允许的域名匹配
        if ($request->getHost() !== $allowedDomain) {
            // 如果不匹配，可以返回错误响应或重定向
            abort(403, '此域名无权访问管理员端');
        }

        return $next($request);
    }
}
