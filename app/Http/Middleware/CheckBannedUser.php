<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckBannedUser
{
    /**
     * Handle an incoming request.
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user;

        // 检查用户是否被封禁
        if ($user && $user['banned']) {
            abort(403, '此账户已被封禁');
        }

        return $next($request);
    }
}
