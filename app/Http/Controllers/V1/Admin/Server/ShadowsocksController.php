<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerShadowsocksSave;
use App\Http\Requests\Admin\ServerShadowsocksUpdate;
use App\Models\ServerShadowsocks;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class ShadowsocksController extends Controller
{
    public function save(ServerShadowsocksSave $request)
    {
        $params = $request->validated();
        if ($request->input('id')) {
            $server = ServerShadowsocks::find($request->input('id'));
            if (!$server) {
                abort(500, '服务器不存在');
            }
            try {
                $server->update($params);
            } catch (\Exception $e) {
                abort(500, '保存失败');
            }
            return response([
                'data' => true
            ]);
        }

        $telegramService = new TelegramService();
        $chatID =config('v2board.telegram_group_id');
        $server = ServerShadowsocks::find($request->input('id'));
        $nodeName = $server->name;
        $text = "🛠 #操作日志\n"
            . "———————————————\n"
            . "下述【节点】有更新：\n"
            . "`{$nodeName}`\n"
            . "请更新订阅\n";
        $telegramService->sendMessage($chatID, $text,'markdown');

        if (!ServerShadowsocks::create($params)) {
            abort(500, '创建失败');
        }



        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerShadowsocks::find($request->input('id'));
            if (!$server) {
                abort(500, '节点ID不存在');
            }
        }
        return response([
            'data' => $server->delete()
        ]);
    }

    public function update(ServerShadowsocksUpdate $request)
    {
        $params = $request->only([
            'show',
        ]);

        $server = ServerShadowsocks::find($request->input('id'));

        if (!$server) {
            abort(500, '该服务器不存在');
        }
        try {
            $server->update($params);
        } catch (\Exception $e) {
            abort(500, '保存失败');
        }

        $telegramService = new TelegramService();
        $chatID =config('v2board.telegram_group_id');
        $nodeName = ServerShadowsocks::find($request->input('id'))->name ?? '未找到节点标题';
        $text = "🛠 #操作日志\n"
            . "———————————————\n"
            . "下述【节点】有更新：\n"
            . "`{$nodeName}`\n"
            . "请更新订阅\n";
        $telegramService->sendMessage($chatID, $text,'markdown');

        return response([
            'data' => true
        ]);
    }

    public function copy(Request $request)
    {
        $server = ServerShadowsocks::find($request->input('id'));
        $server->show = 0;
        if (!$server) {
            abort(500, '服务器不存在');
        }
        if (!ServerShadowsocks::create($server->toArray())) {
            abort(500, '复制失败');
        }

        return response([
            'data' => true
        ]);
    }
}
