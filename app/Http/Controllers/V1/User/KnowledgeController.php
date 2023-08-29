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

            $stream_opts = [
                "ssl" => [
                    "verify_peer"=>false,
                    "verify_peer_name"=>false,
                ],
                "http" => [
                    "header" => [
                        "Content-Type: application/json",
                        "Accept: application/json, text/plain, */*"
                    ]
                ]
            ];
            $appleId_url = "https://apple.laogoubi.net/p/1244XXX";
            $content = file_get_contents($appleId_url, false, stream_context_create($stream_opts));
            $appid_id = ['', '', '', ''];
            if ($content){
                $accounts = json_decode($content, true);
                $rand = count($accounts) > 1 ? random_int(0, count($accounts) - 1) : 0;
                $appid_id[0] = $accounts[$rand]['username'];
                $appid_id[1] = $accounts[$rand]['password'];
                $appid_id[2] = $accounts[$rand]['time'];
                $appid_id[3] = $accounts[$rand]['status'];
            }

            $knowledge['body'] = str_replace('{{apple_id}}', $appid_id[0], $knowledge['body']);
            $knowledge['body'] = str_replace('{{apple_pwd}}', $appid_id[1], $knowledge['body']);
            $knowledge['body'] = str_replace('{{apple_time}}', $appid_id[2], $knowledge['body']);

            if ($appid_id[3] == 1){
                $knowledge['body'] = str_replace('{{apple_status}}', "正常", $knowledge['body']);
            } else {
                $knowledge['body'] = str_replace('{{apple_status}}', "异常", $knowledge['body']);
            }

            $subscribeUrl = Helper::getSubscribeUrl("/api/v1/client/subscribe?token={$user['token']}");
            $knowledge['body'] = str_replace('{{siteName}}', config('v2board.app_name', 'V2Board'), $knowledge['body']);
            $knowledge['body'] = str_replace('{{subscribeUrl}}', $subscribeUrl, $knowledge['body']);
            $knowledge['body'] = str_replace('{{urlEncodeSubscribeUrl}}', urlencode($subscribeUrl), $knowledge['body']);
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

    private function formatAccessData(&$body)
    {
        function getBetween($input, $start, $end){$substr = substr($input, strlen($start)+strpos($input, $start),(strlen($input) - strpos($input, $end))*(-1));return $start . $substr . $end;}
        while (strpos($body, '<!--access start-->') !== false) {
            $accessData = getBetween($body, '<!--access start-->', '<!--access end-->');
            if ($accessData) {
                $body = str_replace($accessData, '<div class="v2board-no-access">'. __("您必须拥有有效的订阅才可以查看该区域的内容，请到左侧购买订阅") .'</div>', $body);
            }
        }
    }
}
