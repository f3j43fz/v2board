<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class Search extends Telegram {
    public $command = '/search';
    public $description = '根据 UUID 搜索用户邮箱。本功能仅限管理员使用。另外，请管理员先在本机器人中绑定自己的TG';

    private $UUID;

    public function handle($message, $match = []) {
        if (!$message->is_private) return;

        // 从数据库中获取所有的管理员
        $admins = User::where('is_admin', 1)->get();

        // 判断当前会话用户是否是管理员
        $isUserAdmin = $admins->contains('telegram_id', $message->chat_id);
        if (!$isUserAdmin) {
            abort(500, '操作无效，本命令仅允许管理员使用！');
        }

        // 判断参数是否为空
        if (!isset($message->args[0])) {
            abort(500, '参数有误，请携带要搜索的用户的 UUID');
        }

        // 使用正则表达式验证UUID格式
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        if (!preg_match($uuidPattern, $message->args[0])) {
            abort(500, '参数有误，请携带有效的 UUID');
        }

        $this->UUID = $message->args[0];

        // 获取对应的用户
        $user = User::where('uuid', $this->UUID)->first();

        if (!$user) {
            abort(500, '用户不存在');
        }

        $telegramService = $this->telegramService;
        $telegramService->sendMessage($message->chat_id, '该UUID对应的邮箱是： ' . $user->mail);
    }
}
