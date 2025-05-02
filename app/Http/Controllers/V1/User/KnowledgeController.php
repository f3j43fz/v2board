<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Knowledge;
use App\Models\User; // 确保引入 User 模型
use App\Services\UserService; // 确保引入 UserService
use App\Utils\Helper;
use Illuminate\Http\Request;
// 如果需要配置，确保引入 Config 门面或使用 config() 助手函数
// use Illuminate\Support\Facades\Config;

class KnowledgeController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $knowledge = Knowledge::where('id', $request->input('id'))
                ->where('show', 1)
                ->first(); // 获取 Eloquent 模型实例

            // 检查文章是否存在
            if (!$knowledge) {
                abort(500, __('Article does not exist'));
            }

            $knowledge = $knowledge->toArray(); // 转换为数组以便修改 body
            $user = User::find($request->user['id']);
            $userService = new UserService();

            // 检查用户的 VPN 订阅是否有效
            $isVpnAvailable = $userService->isAvailable($user);
            // 检查用户的 Emby 订阅是否有效
            $isEmbyAvailable = $this->hasActiveEmbySubscription($user); // 调用新增的辅助方法

            // --- 1. 执行所有占位符替换 ---
            $subscribeUrl = Helper::getSubscribeUrl("/api/v1/client/subscribe?token={$user['token']}");
            $knowledge['body'] = str_replace('{{siteName}}', config('v2board.app_name', 'V2Board'), $knowledge['body']);
            $knowledge['body'] = str_replace('{{subscribeUrl}}', $subscribeUrl, $knowledge['body']);
            $knowledge['body'] = str_replace('{{urlEncodeSubscribeUrl}}', urlencode($subscribeUrl), $knowledge['body']);
            $knowledge['body'] = str_replace('{{withdrawLimit}}', config('v2board.commission_withdraw_limit', 100), $knowledge['body']);
            $currency = (config('v2board.currency') === 'USD') ? '美元' : '元';
            $knowledge['body'] = str_replace('{{currency}}', $currency, $knowledge['body']);
            $knowledge['body'] = str_replace(
                '{{safeBase64SubscribeUrl}}',
                str_replace(
                    array('+', '/', '='),
                    array('-', '_', ''),
                    base64_encode($subscribeUrl)
                ),
                $knowledge['body']
            );
            // << --- 新增：替换 Emby 服务器地址 --- >>
            $knowledge['body'] = str_replace('{{embyServer}}', config('v2board.emby_server_url', 'Emby服务地址未配置'), $knowledge['body']);
            // << --- 结束新增 --- >>


            // --- 2. 处理访问权限 ---

            // 处理通用访问 ...// 仅当 VPN 无效时才隐藏内容
            if (!$isVpnAvailable) {
                $this->formatAccessData($knowledge['body'], '', '', '<div class="v2board-no-access">' . __("您需要购买有效订阅才能查看本区域内容") . '<br><br>' . '<a class="btn btn-hero-primary" style="color:#f5f8fa;" href="#/plan">购买订阅</a>' . '</div>');
            } else {
                // 如果 VPN 有效，则移除标记并显示内容
                $knowledge['body'] = str_replace(['', ''], '', $knowledge['body']);
            }


            // 处理 Emby 访问 ...// 定义 Emby 访问限制时的提示信息
            $embyNoAccessMessage = '';
            if (!$isVpnAvailable) {
                // 情况1: VPN 都没，提示购买通用订阅
                $embyNoAccessMessage = '<div class="v2board-no-access">' . __("您需要购买有效订阅才能查看本区域内容") . '<br><br>' . '<a class="btn btn-hero-primary" style="color:#f5f8fa;" href="#/plan">购买订阅</a>' . '</div>';
            } elseif ($isVpnAvailable && !$isEmbyAvailable) {
                // 情况2: VPN 有，但 Emby 没有，提示购买 Emby
                // 注意: 这里的购买链接可能需要指向你的 Emby 购买页面或套餐 ID (1003)
                // 简单的链接可能还是指向通用套餐页，你可能需要调整前端路由或这里生成特定链接
                $embyNoAccessMessage = '<div class="v2board-no-access">' . __("您需要购买有效的 Emby 服务才能查看本区域内容") . '<br><br>' . '<a class="btn btn-hero-primary" style="color:#f5f8fa;" href="#/plan">购买 Emby 服务</a>' . '</div>';
            }

            // 如果需要隐藏 Emby 内容 (VPN无效 或 VPN有效但Emby无效)
            if (!$isVpnAvailable || ($isVpnAvailable && !$isEmbyAvailable)) {
                $this->formatAccessData($knowledge['body'], '', '', $embyNoAccessMessage);
            } else {
                // 如果 Emby 访问权限满足，移除标记并显示内容
                $knowledge['body'] = str_replace(['', ''], '', $knowledge['body']);
            }


            return response([
                'data' => $knowledge
            ]);
        }

        // 获取知识库列表的逻辑保持不变
        $builder = Knowledge::select(['id', 'category', 'title', 'updated_at'])
            ->where('language', $request->input('language'))
            ->where('show', 1)
            ->orderBy('sort', 'ASC');
        $keyword = $request->input('keyword');
        if ($keyword) {
            $builder = $builder->where(function ($query) use ($keyword) {
                $query->where('title', 'LIKE', "%{$keyword}%")
                    ->orWhere('body', 'LIKE', "%{$keyword}%");
            });
        }

        $knowledges = $builder->get()
            ->groupBy('category');
        return response([
            'data' => $knowledges
        ]);
    }

    // 保持 getBetween 方法不变
    private function getBetween($input, $start, $end)
    {
        $startPos = strpos($input, $start);
        if ($startPos === false) {
            return null;
        }
        $endPos = strpos($input, $end, $startPos + strlen($start));
        if ($endPos === false) {
            return null;
        }
        // 返回包含开始和结束标记的完整块
        return substr($input, $startPos, $endPos - $startPos + strlen($end));
    }

    // 修改 formatAccessData 以接受标记和消息作为参数 (通用化)
    private function formatAccessData(&$body, $startTag, $endTag, $replacementMessage)
    {
        // 循环处理所有匹配的块
        while (($accessData = $this->getBetween($body, $startTag, $endTag)) !== null) {
            $body = str_replace($accessData, $replacementMessage, $body);
        }
    }

    // << --- 新增：检查 Emby 订阅是否有效的辅助方法 --- >>
    /**
     * Check if the user has an active Emby subscription.
     *
     * @param User $user
     * @return bool
     */
    private function hasActiveEmbySubscription(User $user): bool
    {
        // 检查是否有 Emby 用户名 且 Emby 到期时间戳大于当前时间
        return !empty($user->emby_user_name) && $user->emby_expired_at > time();
    }
    // << --- 结束新增 --- >>

    // 注意：原始的 formatAccessData 方法被通用化了，
    // 原有仅在 !isAvailable 时调用的逻辑放到了 fetch 方法中。
    // 如果你希望保留原始的 formatAccessData 方法签名和单一职责，
    // 可以创建一个新的 formatEmbyAccessData 方法，并将权限判断逻辑放入其中。
    // 但使用通用化的 formatAccessData 更简洁一些。
}
