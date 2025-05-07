<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Knowledge;
use App\Models\User;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class KnowledgeController extends Controller
{
    public function fetch(Request $request): JsonResponse
    {
        if ($request->input('id')) {
            return $this->fetchSingleKnowledge($request);
        }

        return $this->fetchKnowledgeList($request);
    }

    private function fetchSingleKnowledge(Request $request): JsonResponse
    {
        $knowledge = Knowledge::where('id', $request->input('id'))
            ->where('show', 1)
            ->first();

        if (!$knowledge) {
            abort(500, __('Article does not exist'));
        }

        $knowledge = $knowledge->toArray();
        $user = User::find($request->user['id']);
        $userService = new UserService();

        if (!$userService->isAvailable($user)) {
            $this->formatRestrictedContent($knowledge['body'], 'access');
        }

        if (!$this->hasActiveEmbySubscription($user)) {
            $this->formatRestrictedContent($knowledge['body'], 'emby');
        }

        $knowledge['body'] = $this->replaceTemplateVariables($knowledge['body'], $user);

        return response([
            'data' => $knowledge
        ]);
    }

    private function fetchKnowledgeList(Request $request): JsonResponse
    {
        $builder = Knowledge::select(['id', 'category', 'title', 'updated_at'])
            ->where('language', $request->input('language'))
            ->where('show', 1)
            ->orderBy('sort', 'ASC');

        if ($keyword = $request->input('keyword')) {
            $builder = $builder->where(function ($query) use ($keyword) {
                $query->where('title', 'LIKE', "%{$keyword}%")
                    ->orWhere('body', 'LIKE', "%{$keyword}%");
            });
        }

        $knowledges = $builder->get()->groupBy('category');

        return response([
            'data' => $knowledges
        ]);
    }

    private function getBetween(string $input, string $start, string $end): string
    {
        $substr = substr($input, strlen($start) + strpos($input, $start), (strlen($input) - strpos($input, $end)) * (-1));
        return $start . $substr . $end;
    }

    private function formatRestrictedContent(string &$body, string $type): void
    {
        $markers = [
            'access' => [
                'start' => '<!--access start-->',
                'end' => '<!--access end-->',
                'message' => __('您需要购买订阅才能查看本区域内容'),
                'link' => '#/plan'
            ],
            'emby' => [
                'start' => '<!--emby access start-->',
                'end' => '<!--emby access end-->',
                'message' => __('您需要购买 Emby 服务才能查看本区域内容'),
                'link' => '#/plan/10'
            ]
        ];

        $config = $markers[$type];
        while (strpos($body, $config['start']) !== false) {
            $restrictedContent = $this->getBetween($body, $config['start'], $config['end']);
            if ($restrictedContent) {
                $replacement = sprintf(
                    '<div class="v2board-no-access">%s<br><br><a class="btn btn-hero-primary" style="color:#f5f8fa;" href="%s">购买订阅</a></div>',
                    $config['message'],
                    $config['link']
                );
                $body = str_replace($restrictedContent, $replacement, $body);
            }
        }
    }

    private function replaceTemplateVariables(string $content, User $user): string
    {
        $subscribeUrl = Helper::getSubscribeUrl("/api/v1/client/subscribe?token={$user['token']}");
        $safeBase64SubscribeUrl = str_replace(
            ['+', '/', '='],
            ['-', '_', ''],
            base64_encode($subscribeUrl)
        );

        $replacements = [
            '{{siteName}}' => config('v2board.app_name', 'V2Board'),
            '{{subscribeUrl}}' => $subscribeUrl,
            '{{urlEncodeSubscribeUrl}}' => urlencode($subscribeUrl),
            '{{withdrawLimit}}' => config('v2board.commission_withdraw_limit', 100),
            '{{embyServerUrl}}' => config('v2board.emby_server_url'),
            '{{currency}}' => (config('v2board.currency') === 'USD') ? '美元' : '元',
            '{{safeBase64SubscribeUrl}}' => $safeBase64SubscribeUrl
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $content
        );
    }

    private function hasActiveEmbySubscription(User $user): bool
    {
        // 检查是否有 Emby 用户名 且 Emby 到期时间戳大于当前时间
        return !empty($user->emby_user_name) && $user->emby_expired_at > time();
    }
}
