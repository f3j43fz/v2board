<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\Ticket;
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
        if ($msg->text === 'invite') {
            $ticket = Ticket::where('id', $ticketId)->first();
            $customer = User::where('id', $ticket->user_id)->first();
            if($customer  && ($customer ->expired_at > time() || $customer ->expired_at === null)){
                $telegram_id = $customer ->telegram_id;
                $msg->text = ($telegram_id > 0) ? $telegramService->createChatInviteLink(config('v2board.telegram_group_id')) . " 请复制该链接，粘贴到浏览器，即可加入群聊。  注意事项：  1. 进群前请设置用户名，否则会被封禁；  2. 进群后请回答一个简单的数学问题，不要瞎回答，否则会被封禁；  3.邀请链接时效性为5分钟，超时后无法加入； 4.多次获取链接但是不入群会被永久封号" : "您还没有绑定我们的机器人，请先到官网左侧的【个人中心】，绑定您的 Telegram 账号。";

            } else{
                $msg->text = "您好，由于您的套餐过期无法入群，请先购买套餐。";
            }

        }
        $ticketService = new TicketService();
        $ticketService->replyByAdmin(
            $ticketId,
            $msg->text,
            $user->id
        );
        $telegramService->sendMessage($msg->chat_id, "#`{$ticketId}` 的工单已回复成功", false,'markdown');
        $telegramService->sendMessageWithAdmin("#`{$ticketId}` 的工单已由 {$user->email} 进行回复", true);
    }
}
