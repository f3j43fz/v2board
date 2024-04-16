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
        if (config('v2board.is_maintenance', 1) == 1) {
            // 如果处于维护模式，重定向到指定页面
            return redirect('/maintenance');
        }

        return $next($request);
    }
}
