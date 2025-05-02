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
            $knowledge = Knowledge::where('id', $request->input('id'))
                ->where('show', 1)
                ->first()
                ->toArray();
            if (!$knowledge) abort(500, __('Article does not exist'));
            $user = User::find($request->user['id']);
            $userService = new UserService();
            if (!$userService->isAvailable($user)) {
                $this->formatAccessData($knowledge['body']);
            }
            $subscribeUrl = Helper::getSubscribeUrl("/api/v1/client/subscribe?token={$user['token']}");
            $knowledge['body'] = str_replace('{{siteName}}', config('v2board.app_name', 'V2Board'), $knowledge['body']);
            $knowledge['body'] = str_replace('{{subscribeUrl}}', $subscribeUrl, $knowledge['body']);
            $knowledge['body'] = str_replace('{{urlEncodeSubscribeUrl}}', urlencode($subscribeUrl), $knowledge['body']);
            $knowledge['body'] = str_replace('{{withdrawLimit}}', config('v2board.commission_withdraw_limit',100), $knowledge['body']);
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
            return response([
                'data' => $knowledge
            ]);
        }
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

    private function getBetween($input, $start, $end)
    {
        $substr = substr($input, strlen($start) + strpos($input, $start), (strlen($input) - strpos($input, $end)) * (-1));
        return $start . $substr . $end;
    }
    private function formatAccessData(&$body)
    {
        while (strpos($body, '<!--access start-->') !== false) {
            $accessData = $this->getBetween($body, '<!--access start-->', '<!--access end-->');
            if ($accessData) {
                $body = str_replace($accessData, '<div class="v2board-no-access">'. __("您需要购买订阅才能查看本区域内容") . '<br><br>' . '<a class="btn btn-hero-primary" style="color:#f5f8fa;" href="#/plan">购买订阅</a>' . '</div>', $body);
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
