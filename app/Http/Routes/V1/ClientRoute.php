<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class ClientRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'client',
            'middleware' => 'client'
        ], function ($router) {
            // 处理可选的 sub_path（去掉首尾 / 以免出现双斜杠）
            $subPath      = trim(config('v2board.sub_path', ''), '/');
            $subscribeUri = $subPath === '' ? 'subscribe' : "subscribe/{$subPath}";
            // Client
            $router->get($subscribeUri, 'V1\\Client\\ClientController@subscribe')
                ->name('client.subscribe');   // <— 追加这一句
            // App
            $router->get('/app/getConfig', 'V1\\Client\\AppController@getConfig');
            $router->get('/app/getVersion', 'V1\\Client\\AppController@getVersion');
        });
    }
}
