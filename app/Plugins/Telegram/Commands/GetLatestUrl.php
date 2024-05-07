<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class GetLatestUrl extends Telegram {
    public $command = '/getlatesturl';
    public $description = '将Telegram账号绑定到网站';

    public function handle($message, $match = []) {
        $telegramService = $this->telegramService;
        if (!$message->is_private) {
            abort(500, '请在我们的群组中发送本命令噢~');
            return;
        }
        $text = sprintf(
            "%s的最新网址是：%s",
            config('v2board.app_name', 'V2Board'),
            config('v2board.app_url')
        );
        $telegramService->sendMessage($message->chat_id, $text, false,'markdown');
    }
}
