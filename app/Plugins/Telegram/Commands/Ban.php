<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class Ban extends Telegram {
    public $command = '/ban';
    public $description = '封禁用户。本功能仅限管理员使用。另外，请管理员先在本机器人中绑定自己的TG';

    private $email;
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
            abort(500, '参数有误，请携带要封禁的用户的 UUID 或者 邮箱');
        }

        // 提取参数中的 邮箱 或者 UUID
        $UUIDOrEmail = $message->args[0];
        if (filter_var($UUIDOrEmail, FILTER_VALIDATE_EMAIL)) {
            $this->email = $UUIDOrEmail;
        } else {
            // 使用正则表达式验证UUID格式
            $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
            if (preg_match($uuidPattern, $UUIDOrEmail)) {
                $this->UUID = $UUIDOrEmail;
            } else {
                abort(500, '参数有误，请携带有效的 UUID 或者 邮箱');
            }
        }


        // 获取对应的用户
        $user = null;
        if (isset($this->email)) {
            $user = User::where('email', $this->email)->first();
        } elseif (isset($this->UUID)) {
            $user = User::where('uuid', $this->UUID)->first();
        }

        if (!$user) {
            abort(500, '用户不存在');
        }
        if ($user->is_admin) {
            abort(500, '不能封禁管理员');
        }
        if ($user->banned) {
            abort(500, '该账号已经被封禁过了，无需重复操作');
        }
        $user->banned = 1;
        if (!$user->save()) {
            abort(500, '设置失败');
        }
        $telegramService = $this->telegramService;
        $telegramService->sendMessage($message->chat_id, '封禁成功');
    }
}
