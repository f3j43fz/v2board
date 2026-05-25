<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class PassportRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'passport'
        ], function ($router) {
            // Auth — 限速防止暴力破解 / spam
            $router->post('/auth/register',         ['middleware' => 'throttle:5,1',  'uses' => 'V1\\Passport\\AuthController@register']);
            $router->post('/auth/login',            ['middleware' => 'throttle:10,1', 'uses' => 'V1\\Passport\\AuthController@login']);
            $router->get ('/auth/token2Login',      ['middleware' => 'throttle:20,1', 'uses' => 'V1\\Passport\\AuthController@token2Login']);
            $router->post('/auth/forget',           ['middleware' => 'throttle:5,1',  'uses' => 'V1\\Passport\\AuthController@forget']);
            $router->post('/auth/getQuickLoginUrl', ['middleware' => 'throttle:30,1', 'uses' => 'V1\\Passport\\AuthController@getQuickLoginUrl']);
            // Comm
            $router->post('/comm/sendEmailVerify',  ['middleware' => 'throttle:3,1',  'uses' => 'V1\\Passport\\CommController@sendEmailVerify']);
            $router->post('/comm/pv',               ['middleware' => 'throttle:10,1', 'uses' => 'V1\\Passport\\CommController@pv']);
        });
    }
}
