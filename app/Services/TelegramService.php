<?php
namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Models\User;
use \Curl\Curl;
use Illuminate\Mail\Markdown;

class TelegramService {
    protected $api;

    public function __construct($token = '')
    {
        $this->api = 'https://api.telegram.org/bot' . config('v2board.telegram_bot_token', $token) . '/';
    }

    public function sendMessage(int $chatId, string $text, string $parseMode = '')
    {
        if ($parseMode === 'markdown') {
            $text = str_replace('_', '\_', $text);
        }
        $this->request('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode
        ]);
    }

    public function approveChatJoinRequest(int $chatId, int $userId)
    {
        $this->request('approveChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function declineChatJoinRequest(int $chatId, int $userId)
    {
        $this->request('declineChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function createChatInviteLink(int $chatId)
    {
        // 设置最大加入成员数量为1
        $memberLimit = 1;

        // 设置失效时间为5分钟后
        $expireDate = time() + 300; // 300秒 = 5分钟

        $response = $this->request('createChatInviteLink', [
            'chat_id' => $chatId,
            'expire_date' => $expireDate,
            'member_limit' => $memberLimit
        ]);

        return $response->result->invite_link;
    }


    public function removeExpiredUsersFromGroup(int $chatId, int $expiredDaysAgo): array
    {
        $deletedUsers = [];
        $expiredTime = time() - $expiredDaysAgo * 86400;

        $users = User::whereNotNull('telegram_id')
            ->where('expired_at', '<', $expiredTime)
            ->get();

        foreach ($users as $user) {
            $tgId = $user->telegram_id;

            // 判断用户是否仍然有效
            if (!$this->isUserActive($chatId, $tgId)) {
                // 用户已失效，无需移除
                continue;
            }

            $this->request('banChatMember', [
                'chat_id' => $chatId,
                'user_id' => $tgId
            ]);

//            $this->request('unbanChatMember', [
//                'chat_id' => $chatId,
//                'user_id' => $tgId
//            ]);
            $deletedUsers[] = [
                'id' => $user->id,
                'telegram_id' => $tgId
            ];
            $message = "抱歉，由于您的套餐已过期超过 {$expiredDaysAgo} 天，管理员将您移除了群聊。您可以续费套餐，然后发工单获取入群链接。";
            SendTelegramJob::dispatch($tgId, $message);
        }

        return $deletedUsers;
    }

    private function isUserActive(int $userId): bool
    {
        $response = $this->request('sendMessage', [
            'chat_id' => $userId,
            'text' => 'Test'
        ]);

        if (isset($response->ok) && $response->ok) {
            return true;  // 用户存在
        } else {
            return false;  // 用户已注销
        }
    }

    public function getMe()
    {
        return $this->request('getMe');
    }

    public function setWebhook(string $url)
    {
        return $this->request('setWebhook', [
            'url' => $url
        ]);
    }

    private function request(string $method, array $params = [])
    {
        $curl = new Curl();
        $curl->get($this->api . $method . '?' . http_build_query($params));
        $response = $curl->response;
        $curl->close();
        if (!isset($response->ok)) abort(500, '请求失败');
        if (!$response->ok) {
            abort(500, '来自TG的错误：' . $response->description);
        }
        return $response;
    }

    public function sendMessageWithAdmin($message, $isStaff = false)
    {
        if (!config('v2board.telegram_bot_enable', 0)) return;
        $users = User::where(function ($query) use ($isStaff) {
            $query->where('is_admin', 1);
            if ($isStaff) {
                $query->orWhere('is_staff', 1);
            }
        })
            ->where('telegram_id', '!=', NULL)
            ->get();
        foreach ($users as $user) {
            SendTelegramJob::dispatch($user->telegram_id, $message);
        }
    }
}
