<?php

/**
 * Laravel - A PHP Framework For Web Artisans
 *
 * @package  Laravel
 * @author   Taylor Otwell <taylor@laravel.com>
 */

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| our application. We just need to utilize it! We'll simply require it
| into the script here so that we don't have to worry about manual
| loading any of our classes later on. It feels great to relax.
|
*/
require __DIR__.'/../JumpJump/WxqqJump.php';

// 检查密码验证是否通过
function isPasswordValid($password) {
    // 在这里添加您的密码验证逻辑
    return $password === '5201';
}

// 启动会话
session_start();

// 检查会话中是否存在密码验证通过的标识
$authenticated = $_SESSION['authenticated'] ?? false;

// 如果请求的路径不是密码验证页面，并且未通过密码验证，则进行验证
if ($_SERVER['REQUEST_URI'] !== '/authauth/auth.html' && !$authenticated) {
    // 检查密码验证是否通过
    if (!isPasswordValid($_POST['password'])) {
        // 密码验证失败，重定向到密码验证页面
        header('Location: /authauth/auth.html');
        exit;
    }

    // 将密码验证通过的标识存储在会话中
    $_SESSION['authenticated'] = true;
}



require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Turn On The Lights
|--------------------------------------------------------------------------
|
| We need to illuminate PHP development, so let us turn on the lights.
| This bootstraps the framework and gets it ready for use, then it
| will load up this application so that we can run it and send
| the responses back to the browser and delight our users.
|
*/

$app = require_once __DIR__.'/../bootstrap/app.php';

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| Once we have the application, we can handle the incoming request
| through the kernel, and send the associated response back to
| the client's browser allowing them to enjoy the creative
| and wonderful application we have prepared for them.
|
*/

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

$response->send();

$kernel->terminate($request, $response);
