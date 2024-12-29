<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\TicketSave;
use App\Http\Requests\User\TicketWithdraw;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TelegramService;
use App\Services\TicketService;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ip2Region;

class TicketController extends Controller
{
    public function fetch(Request $request)
    {
        $ticketID = $this->antiXss->xss_clean($request->input('id'));
        $userId = $this->antiXss->xss_clean($request->user['id']);

        if ($ticketID) {
            $ticket = Ticket::where('id', $ticketID)
                ->where('user_id', $userId)
                ->firstOrFail();
            $ticket['message'] = TicketMessage::where('ticket_id', $ticket->id)->get();
            for ($i = 0; $i < count($ticket['message']); $i++) {
                if ($ticket['message'][$i]['user_id'] !== $ticket->user_id) {
                    $ticket['message'][$i]['is_me'] = true;
                } else {
                    $ticket['message'][$i]['is_me'] = false;
                }
            }
            return response(['data' => $ticket]);
        }

        $ticket = Ticket::where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->get();
        return response([
            'data' => $ticket
        ]);
    }

    public function save(TicketSave $request)
    {
        if (!filter_var($request->ip(), FILTER_VALIDATE_IP)) {
            abort(500, '非法IP地址');
        }

        $message = $this->antiXss->xss_clean($request->input('message'));
        $subject = $this->antiXss->xss_clean($request->input('subject'));
        $level = $this->antiXss->xss_clean($request->input('level'));
        DB::beginTransaction();
        if ((int)Ticket::where('status', 0)->where('user_id', $request->user['id'])->lockForUpdate()->count()) {
            abort(500, __('There are other unresolved tickets'));
        }
        $ticket = Ticket::create(array_merge($request->only([
            'subject',
            'level'
        ]), [
            'user_id' => $request->user['id']
        ]));
        if (!$ticket) {
            DB::rollback();
            abort(500, __('Failed to open ticket'));
        }

        $ticketMessage = TicketMessage::create([
            'user_id' => $request->user['id'],
            'ticket_id' => $ticket->id,
            'message' => $message
        ]);
        if (!$ticketMessage) {
            DB::rollback();
            abort(500, __('Failed to open ticket'));
        }
        DB::commit();

        //ISPInfo
        $ISPInfo = Helper::getUserISP($request->ip());

        //tansfer
        $user = User::find($request->user['id']);
        $transferEnable = ($user->transfer_enable) / (1024*1024*1024);
        $transferUsed = ($user->u + $user->d) / (1024*1024*1024);
        $transferUsed = number_format($transferUsed, 2);

        //email
        $email = $user->email;

        //planName and expiredTime
        $planID = $user->plan_id;
        $plan = Plan::find($planID);
        $planName = '';
        $expiredTime = null;
        if ($plan) {
            $planName = $plan->name;
            $expiredTime = ($plan->onetime_price > 0 || $plan->setup_price > 0)? "永不过期" : date('Y-m-d', $user->expired_at);
        }

        $this->sendNotify($ticket, $message, $ISPInfo, $planName, $transferEnable, $transferUsed, $expiredTime, $email);

        //发送邮件，告知用户工单已经生成，待处理
        $ticketService = new TicketService();
        $ticketService->notifyTicketGenerated($user, $ticket->id, $subject, $level, $message);

        return response([
            'data' => true
        ]);
    }


    public function reply(Request $request)
    {
        if (!filter_var($request->ip(), FILTER_VALIDATE_IP)) {
            abort(500, '非法IP地址');
        }

        $id = $this->antiXss->xss_clean($request->input('id'));
        $message = $this->antiXss->xss_clean($request->input('message'));

        if (empty($id)) {
            abort(500, __('Invalid parameter'));
        }
        if (empty($message)) {
            abort(500, __('Message cannot be empty'));
        }
        $ticket = Ticket::where('id', $id)
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$ticket) {
            abort(500, __('Ticket does not exist'));
        }
        if ($ticket->status) {
            abort(500, __('The ticket is closed and cannot be replied'));
        }
        if ($request->user['id'] == $this->getLastMessage($ticket->id)->user_id) {
            abort(500, __('Please wait for the technical enginneer to reply'));
        }
        $ticketService = new TicketService();
        if (!$ticketService->reply(
            $ticket,
            $message,
            $request->user['id']
        )) {
            abort(500, __('Ticket reply failed'));
        }

        //ISPInfo
        $ISPInfo = Helper::getUserISP($request->ip());

        //tansfer
        $user = User::find($request->user['id']);
        $transferEnable = ($user->transfer_enable) / (1024*1024*1024);
        $transferUsed = ($user->u + $user->d) / (1024*1024*1024);
        $transferUsed = number_format($transferUsed, 2);

        //email
        $email = $user->email;

        //planName and expiredTime
        $planID = $user->plan_id;
        $plan = Plan::find($planID);
        $planName = '';
        $expiredTime = null;
        if ($plan) {
            $planName = $plan->name;
            $expiredTime = ($plan->onetime_price > 0)? "永不过期" : date('Y-m-d', $user->expired_at);
        }

        $this->sendNotify($ticket, $message, $ISPInfo, $planName, $transferEnable, $transferUsed, $expiredTime, $email);
        return response([
            'data' => true
        ]);
    }


    public function close(Request $request)
    {
        $id = $this->antiXss->xss_clean($request->input('id'));
        if (empty($id)) {
            abort(500, __('Invalid parameter'));
        }
        $ticket = Ticket::where('id', $id)
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$ticket) {
            abort(500, __('Ticket does not exist'));
        }
        $ticket->status = 1;
        if (!$ticket->save()) {
            abort(500, __('Close failed'));
        }
        return response([
            'data' => true
        ]);
    }

    private function getLastMessage($ticketId)
    {
        return TicketMessage::where('ticket_id', $ticketId)
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function withdraw(TicketWithdraw $request)
    {
        if (!filter_var($request->ip(), FILTER_VALIDATE_IP)) {
            abort(500, '非法IP地址');
        }
        $withdraw_method = $this->antiXss->xss_clean($request->input('withdraw_method'));
        $withdraw_account = $this->antiXss->xss_clean($request->input('withdraw_account'));
        if ((int)config('v2board.withdraw_close_enable', 0)) {
            abort(500, 'user.ticket.withdraw.not_support_withdraw');
        }
        if (!in_array(
            $withdraw_method,
            config(
                'v2board.commission_withdraw_method',
                Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT
            )
        )) {
            abort(500, __('Unsupported withdrawal method'));
        }

        if ( $withdraw_method == 'USDT' && !preg_match('/^T[a-zA-Z0-9]{33}$/', $withdraw_account)) {
            abort(500, __('Unsupported USDT-TRC20 address'));
        }

        $user = User::find($request->user['id']);
        $limit = config('v2board.commission_withdraw_limit', 100);
        if ($limit > ($user->commission_balance / 100)) {
            $currency = config('v2board.currency') == 'USD' ? "美元" : "元";
            abort(500, __('The current required minimum withdrawal commission is :limit :currency', ['limit' => $limit, 'currency' => $currency]));
        }
        DB::beginTransaction();
        $subject = __('[Commission Withdrawal Request] This ticket is opened by the system');
        $ticket = Ticket::create([
            'subject' => $subject,
            'level' => 2,
            'user_id' => $request->user['id']
        ]);
        if (!$ticket) {
            DB::rollback();
            abort(500, __('Failed to open ticket'));
        }
        $message = sprintf("%s\r\n%s",
            __('Withdrawal method') . "：" . $withdraw_method,
            __('Withdrawal account') . "：" . $withdraw_account
        );
        $ticketMessage = TicketMessage::create([
            'user_id' => $request->user['id'],
            'ticket_id' => $ticket->id,
            'message' => $message
        ]);
        if (!$ticketMessage) {
            DB::rollback();
            abort(500, __('Failed to open ticket'));
        }
        DB::commit();

        //ISPInfo
        $ISPInfo = Helper::getUserISP($request->ip());

        //tansfer
        $user = User::find($request->user['id']);
        $transferEnable = ($user->transfer_enable) / (1024*1024*1024);
        $transferUsed = ($user->u + $user->d) / (1024*1024*1024);
        $transferUsed = number_format($transferUsed, 2);

        //email
        $email = $user->email;

        //planName and expiredTime
        $planID = $user->plan_id;
        $plan = Plan::find($planID);
        $planName = '';
        $expiredTime = null;
        if ($plan) {
            $planName = $plan->name;
            $expiredTime = ($plan->onetime_price > 0)? "永不过期" : date('Y-m-d', $user->expired_at);
        }

        $this->sendNotify($ticket, $message, $ISPInfo, $planName, $transferEnable, $transferUsed, $expiredTime, $email);
        return response([
            'data' => true
        ]);
    }

    private function sendNotify(Ticket $ticket, string $message, $ISPInfo, $planName, $transferEnable, $transferUsed, $expiredTime, $email)
    {
        $telegramService = new TelegramService();
        $notification = "📮工单提醒 #{$ticket->id}\n"
            . "———————————————\n"
            . "邮箱：\n`{$email}`\n"
            . "运营商：\n`{$ISPInfo}`\n"
            . "套餐及流量：\n`{$planName}` | `{$transferUsed}` of `{$transferEnable}` GB\n"
            . "到期时间：\n`{$expiredTime}`\n"
            . "主题：\n`{$ticket->subject}`\n"
            . "内容：\n`{$message}`";

        $telegramService->sendMessageWithAdmin($notification, true);
    }
}
