<?php
namespace App\Services;


use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TicketService {
    public function reply($ticket, $message, $userId)
    {
        DB::beginTransaction();
        $ticketMessage = TicketMessage::create([
            'user_id' => $userId,
            'ticket_id' => $ticket->id,
            'message' => $message
        ]);
        if ($userId !== $ticket->user_id) {
            $ticket->reply_status = 0;
        } else {
            $ticket->reply_status = 1;
        }
        if (!$ticketMessage || !$ticket->save()) {
            DB::rollback();
            return false;
        }
        DB::commit();
        return $ticketMessage;
    }

    public function replyByAdmin($ticketId, $message, $userId):void
    {
        $ticket = Ticket::where('id', $ticketId)
            ->first();
        if (!$ticket) {
            abort(500, '工单不存在');
        }
        $ticket->status = 0;
        DB::beginTransaction();
        $ticketMessage = TicketMessage::create([
            'user_id' => $userId,
            'ticket_id' => $ticket->id,
            'message' => $message
        ]);
        if ($userId !== $ticket->user_id) {
            $ticket->reply_status = 0;
        } else {
            $ticket->reply_status = 1;
        }
        if (!$ticketMessage || !$ticket->save()) {
            DB::rollback();
            abort(500, '工单回复失败');
        }
        DB::commit();
        $this->sendEmailNotify($ticket, $ticketMessage);
    }

    // 半小时内不再重复通知
    private function sendEmailNotify(Ticket $ticket, TicketMessage $ticketMessage)
    {
        $user = User::find($ticket->user_id);
        $cacheKey = 'ticket_sendEmailNotify_' . $ticket->user_id;
        if (!Cache::get($cacheKey)) {
            Cache::put($cacheKey, 1, 1800);
            $userName = explode('@', $user->email)[0];
            $ticketID = $ticket->id;
            $subject = $ticket->subject;
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => '[工单ID： ' . $ticketID . '] ' .  $subject,
                'template_name' => 'notifyTicketReplied',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => config('v2board.app_url'),
                    'content' => $ticketMessage->message,
                    'userName' => $userName,
                    'ticketID' =>$ticketID,
                    'subject' => $subject
                ]
            ]);
        }
    }

    // 用户创建工单后，发邮件提示用户
    public function notifyTicketGenerated(User $user, $ticketID, $subject, $level, $message)
    {
        $userName = explode('@', $user->email)[0];
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => '[工单ID： ' . $ticketID . '] ' .  $subject,
            'template_name' => 'notifyTicketGenerated',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'content' => $message,
                'level' => $level,
                'userName' => $userName,
                'subject' => $subject,
            ]
        ]);

    }

}
