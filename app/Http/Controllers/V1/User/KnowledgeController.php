<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Knowledge;
use App\Models\User;          // 确保引入 User 模型
use App\Services\UserService; // 确保引入 UserService
use App\Utils\Helper;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $knowledge = Knowledge::where('id', $request->input('id'))
                ->where('show', 1)
                ->first(); // 获取模型实例，以便后续修改 body

            if (!$knowledge) {
                abort(500, __('Article does not exist'));
            }

            // 获取完整的 User 模型，以便访问 emby 字段
            $user = User::find($request->user['id']);
            if (!$user) {
                // 处理用户不存在的异常情况，虽然理论上不应发生
                abort(403, '用户不存在');
            }

            $userService = new UserService(); // 用于检查 VPN 状态

            // 先进行占位符替换
            $subscribeUrl = Helper::getSubscribeUrl("/api/v1/client/subscribe?token={$user->token}"); // 使用模型属性 token
            // 从配置获取 Emby 服务器地址，提供一个空字符串作为默认值
            $embyServerUrl = config('v2board.emby_server_url', '');

            $knowledge->body = str_replace('{{siteName}}', config('v2board.app_name', 'V2Board'), $knowledge->body);
            $knowledge->body = str_replace('{{subscribeUrl}}', $subscribeUrl, $knowledge->body);
            $knowledge->body = str_replace('{{urlEncodeSubscribeUrl}}', urlencode($subscribeUrl), $knowledge->body);
            $knowledge->body = str_replace('{{embyServer}}', $embyServerUrl, $knowledge->body); // <<--- 新增 Emby 服务器替换
            $knowledge->body = str_replace('{{withdrawLimit}}', config('v2board.commission_withdraw_limit', 100), $knowledge->body);
            $currency = (config('v2board.currency') === 'USD') ? '美元' : '元';
            $knowledge->body = str_replace('{{currency}}', $currency, $knowledge->body);
            $knowledge->body = str_replace(
                '{{safeBase64SubscribeUrl}}',
                str_replace(
                    array('+', '/', '='),
                    array('-', '_', ''),
                    base64_encode($subscribeUrl)
                ),
                $knowledge->body
            );

            // --- 应用访问控制 ---
            // 1. 处理 Emby 专属内容 (...)
            //    如果用户没有有效的 Emby 订阅，则隐藏这部分内容
            $this->formatEmbyAccessData($user, $knowledge->body);

            // 2. 处理 VPN 专属内容 (...)
            //    如果用户没有有效的 VPN 订阅，则隐藏这部分内容
            if (!$userService->isAvailable($user)) {
                $this->formatAccessData($knowledge->body);
            }
            // --- 结束访问控制 ---


            // 将模型转换为数组以进行响应
            $knowledgeArray = $knowledge->toArray();

            return response([
                'data' => $knowledgeArray
            ]);
        }

        // --- 获取知识库列表的代码保持不变 ---
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
        // --- 列表代码结束 ---
    }

    /**
     * 检查用户是否有有效的 Emby 订阅
     * @param User $user
     * @return bool
     */
    private function hasActiveEmby(User $user): bool
    {
        // 检查 emby_user_name 是否存在且不为空
        // 并且 emby_expired_at 是否存在且大于当前时间戳
        return !empty($user->emby_user_name) &&
            $user->emby_expired_at !== null &&
            $user->emby_expired_at > time();
    }


    /**
     * 格式化 Emby 专属内容的访问权限
     * 如果用户没有权限，则替换 到 之间的内容
     * @param User $user
     * @param string &$body 知识库文章内容 (引用传递)
     */
    private function formatEmbyAccessData(User $user, &$body)
    {
        // 如果用户没有有效的 Emby 订阅
        if (!$this->hasActiveEmby($user)) {
            // 循环处理可能存在的多个 Emby 访问区域
            while (($startPos = strpos($body, '')) !== false) {
                $endPos = strpos($body, '', $startPos);
                if ($endPos === false) {
                    // 如果找不到结束标签，退出循环以防死循环
                    break;
                }

                // 包含开始和结束标签的完整区域
                $accessBlockLength = $endPos - $startPos + strlen('');
                $accessBlock = substr($body, $startPos, $accessBlockLength);

                if ($accessBlock) {
                    // 定义无权限时显示的 HTML 内容
                    $replacementHtml = '<div class="v2board-no-access" style="padding: 15px; border: 1px dashed #ffc107; background-color: #fff3cd; border-radius: 4px; margin: 15px 0; text-align: center;">'.
                        __("您需要拥有有效的 Emby 服务才能查看本区域内容") . // Emby 专属提示信息
                        '<br><br>' .
                        // 可以链接到 Emby 套餐 (假设 ID 为 10)
                        '<a class="btn btn-warning" style="color:#212529;" href="#/plan/10">'.__('前往了解 Emby 服务').'</a>' .
                        '</div>';
                    // 替换掉整个访问控制块
                    $body = substr_replace($body, $replacementHtml, $startPos, $accessBlockLength);
                } else {
                    // 如果提取的块为空（理论上不应发生），退出循环
                    break;
                }
            }
        }
        // 如果用户有权限，则无需做任何操作，内容会正常显示
    }


    /**
     * 格式化 VPN 专属内容的访问权限 (保持原有逻辑)
     * @param string &$body
     */
    private function formatAccessData(&$body)
    {
        // 循环处理可能存在的多个 VPN 访问区域
        while (($startPos = strpos($body, '')) !== false) {
            $endPos = strpos($body, '', $startPos);
            if ($endPos === false) {
                break;
            }

            $accessBlockLength = $endPos - $startPos + strlen('');
            $accessBlock = substr($body, $startPos, $accessBlockLength);

            if ($accessBlock) {
                // VPN 访问权限的提示信息
                $replacementHtml = '<div class="v2board-no-access" style="padding: 15px; border: 1px dashed #dc3545; background-color: #f8d7da; border-radius: 4px; margin: 15px 0; text-align: center;">'.
                    __("您需要购买订阅才能查看本区域内容") .
                    '<br><br>' .
                    '<a class="btn btn-danger" style="color:#f5f8fa;" href="#/plan">'.__('购买订阅').'</a>' . // 链接到通用套餐页
                    '</div>';
                $body = substr_replace($body, $replacementHtml, $startPos, $accessBlockLength);
            } else {
                break;
            }
        }
    }

    /**
     * 获取两个字符串之间的子串 (这个方法可能不再需要，因为我们直接替换整个块)
     * @param $input
     * @param $start
     * @param $end
     * @return bool|string
     */
    private function getBetween($input, $start, $end)
    {
        // 修正此方法以正确提取内容，虽然新的格式化方法可能不再需要它
        $startPos = strpos($input, $start);
        if ($startPos === false) {
            return false;
        }
        $startPos += strlen($start);
        $endPos = strpos($input, $end, $startPos);
        if ($endPos === false) {
            return false;
        }
        return substr($input, $startPos, $endPos - $startPos);
        // 注意：原始的 getBetween 方法实现有误，这里也修正了，但 formatEmbyAccessData 和 formatAccessData 已改为直接替换整个块。
    }
}
