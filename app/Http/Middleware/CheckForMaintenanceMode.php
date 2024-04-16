<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckForMaintenanceMode
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (config('v2board.is_maintenance', 1) === true) {
            // 直接使用 abort 函数生成 HTTP 503 响应
            abort(503, '网站维护中，请您稍后再试');
        }

        return $next($request);
    }
}
