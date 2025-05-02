<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Knowledge;
use App\Models\User;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            // --- 这部分代码保持不变 ---
            $knowledge = Knowledge::where('id', $request->input('id'))
                ->where('show', 1)
                ->first(); // 修改：先获取 Eloquent 对象

            if (!$knowledge) abort(500, __('Article does not exist'));

            $user = User::find($request->user['id']);
            $userService = new UserService();
            $isVpnAvailable = $userService->isAvailable($user); // 获取 VPN 是否可用

            $knowledge = $knowledge->toArray(); // 现在转为数组

            // --- 之前的 VPN 访问控制逻辑 (保持不变) ---
            // 注意：原逻辑是 !isAvailable 时才处理，确保这是你想要的行为
            if (!$isVpnAvailable) {
                $this->formatAccessData($knowledge['body']);
            }
            // --- 结束之前的 VPN 访问控制逻辑 ---


            // --- 之前的占位符替换逻辑 (保持不变) ---
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
            // --- 结束之前的占位符替换逻辑 ---


            // --- 新增：Emby 服务器地址替换 ---
            $knowledge['body'] = str_replace(
                '{{embyServer}}',
                config('v2board.emby_server_url', ''), // 如果没配置，则替换为空字符串
                $knowledge['body']
            );
            // --- 结束新增 Emby 服务器地址替换 ---


            // --- 新增：Emby 访问控制逻辑 ---
            // 1. 检查用户是否有有效的 Emby 订阅
            $hasActiveEmby = (!empty($user->emby_user_name) && !empty($user->emby_expired_at) && $user->emby_expired_at > time());

            // 2. 根据是否有有效 Emby 订阅来处理 标签
            if (!$hasActiveEmby) {
                // 如果没有有效 Emby 订阅，调用 formatEmbyAccessData 隐藏内容并显示提示
                $this->formatEmbyAccessData($knowledge['body']);
            } else {
                // 如果有有效 Emby 订阅，则直接移除标签，显示内容
                $knowledge['body'] = str_replace('', '', $knowledge['body']);
                $knowledge['body'] = str_replace('', '', $knowledge['body']);
            }
            // --- 结束新增 Emby 访问控制逻辑 ---


            // --- 返回响应 (保持不变) ---
            return response([
                'data' => $knowledge
            ]);
        }

        // --- 获取知识库列表的逻辑 (保持不变) ---
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

    // --- getBetween 方法保持不变 ---
    private function getBetween($input, $start, $end)
    {
        // 修正：确保能正确处理各种情况，更健壮的实现
        $startPos = strpos($input, $start);
        if ($startPos === false) {
            return null;
        }
        $startPos += strlen($start);
        $endPos = strpos($input, $end, $startPos);
        if ($endPos === false) {
            return null;
        }
        // 返回包含标签的完整块
        return substr($input, $startPos - strlen($start), $endPos - ($startPos - strlen($start)) + strlen($end));
        // 原有逻辑: return $start . substr(...) . $end; // 这个可能在复杂嵌套或标签出错时有问题
    }

    // --- formatAccessData 方法保持不变 ---
    // 确保这个方法的逻辑是你期望的 (当前是在 !isAvailable 时隐藏内容)
    private function formatAccessData(&$body)
    {
        while (strpos($body, '') !== false) {
            $accessData = $this->getBetween($body, '', '');
            if ($accessData) { // 检查 getBetween 是否成功返回
                $body = str_replace($accessData, '<div class="v2board-no-access">'. __("您需要购买订阅才能查看本区域内容") . '<br><br>' . '<a class="btn btn-hero-primary" style="color:#f5f8fa;" href="#/plan">购买订阅</a>' . '</div>', $body);
            } else {
                // 如果 getBetween 失败（可能标签不匹配），跳出循环避免死循环
                break;
            }
        }
    }


    /**
     * 格式化 Emby 访问数据，隐藏无权访问的内容
     * @param string $body 知识库文章内容 (引用传递)
     */
    private function formatEmbyAccessData(&$body)
    {
        $startTag = '';
        $endTag = '';
        // 使用循环处理可能存在的多个 emby-access 块
        while (($startPos = strpos($body, $startTag)) !== false) {
            $endPos = strpos($body, $endTag, $startPos);

            // 如果找不到结束标签或标签顺序错误，则跳出循环防止死循环
            if ($endPos === false || $endPos <= $startPos) {
                break;
            }

            // 提取包括开始和结束标签在内的整个块
            $block = substr($body, $startPos, $endPos - $startPos + strlen($endTag));

            // 替换为无权限提示信息 (可以自定义样式和文字)
            $noAccessMessage = '<div class="v2board-no-access" style="padding: 15px; margin-bottom: 20px; border: 1px dashed #dc3545; background-color: #f8d7da; color: #721c24; border-radius: 4px;">'
                . '<p style="margin:0;">🔒 ' . __("此部分内容需要有效的 Emby 订阅才能查看。") . '</p>'
                // 可选：添加购买链接，指向你的 Emby 套餐购买页面 (如果 Emby 套餐有单独页面)
                // . '<br><a class="btn btn-sm btn-danger" style="color:white; text-decoration:none;" href="#/plan/' . 1003 . '">前往购买/续费 Emby 订阅</a>'
                . '</div>';
            $body = str_replace($block, $noAccessMessage, $body);
        }
    }
}
