<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\TicketService;

class ReplyTicket extends Telegram {
    public $regex = '/[#](.*)/';
    public $description = '快速工单回复';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $this->replayTicket($message, $match[1]);
    }


    private function replayTicket($msg, $ticketId)
    {
        $user = User::where('telegram_id', $msg->chat_id)->first();
        if (!$user) {
            abort(500, '用户不存在');
        }
        if (!$msg->text) return;
        if (!($user->is_admin || $user->is_staff)) return;
        $telegramService = $this->telegramService;
        if ($msg->text == 'invite'){
            $msg->text = $telegramService->createChatInviteLink(config('v2board.telegram_group_id')) . '  ' . '请复制链接，粘贴到浏览器，即可加入群聊。  注意事项：  1. 进群前请设置用户名，否则会被封禁；  2. 进群后请回答一个简单的数学问题，不要瞎回答，否则会被封禁；  3.邀请链接时效性为5分钟，超时后无法加入';
        }
        $ticketService = new TicketService();
        $ticketService->replyByAdmin(
            $ticketId,
            $msg->text,
            $user->id
        );
        $telegramService->sendMessage($msg->chat_id, "#`{$ticketId}` 的工单已回复成功", 'markdown');
        $telegramService->sendMessageWithAdmin("#`{$ticketId}` 的工单已由 {$user->email} 进行回复", true);
    }
}
