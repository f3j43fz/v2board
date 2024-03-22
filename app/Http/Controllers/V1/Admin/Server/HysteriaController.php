<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerHysteria;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class HysteriaController extends Controller
{
    public function save(Request $request)
    {
        $params = $request->validate([
            'show' => '',
            'name' => 'required',
            'group_id' => 'required|array',
            'route_id' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'tags' => 'nullable|array',
            'rate' => 'required|numeric',
            'up_mbps' => 'required|numeric|min:1',
            'down_mbps' => 'required|numeric|min:1',
            'server_name' => 'nullable',
            'insecure' => 'required|in:0,1',
            'obfs_type' => 'nullable|in:salamander',
            'ignore_client_bandwidth' => 'required|in:0,1'
        ]);

        if ($request->input('id')) {
            $server = ServerHysteria::find($request->input('id'));
            if (!$server) {
                abort(500, '服务器不存在');
            }
            try {
                $server->update($params);
            } catch (\Exception $e) {
                abort(500, '保存失败');
            }
            $this->notify($server->name);
            return response([
                'data' => true
            ]);
        }

        if (!ServerHysteria::create($params)) {
            abort(500, '创建失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerHysteria::find($request->input('id'));
            if (!$server) {
                abort(500, '节点ID不存在');
            }
        }
        return response([
            'data' => $server->delete()
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'show' => 'in:0,1'
        ], [
            'show.in' => '显示状态格式不正确'
        ]);
        $params = $request->only([
            'show',
        ]);

        $server = ServerHysteria::find($request->input('id'));

        if (!$server) {
            abort(500, '该服务器不存在');
        }
        try {
            $server->update($params);
        } catch (\Exception $e) {
            abort(500, '保存失败');
        }
        $this->notify($server->name);
        return response([
            'data' => true
        ]);
    }

    public function copy(Request $request)
    {
        $server = ServerHysteria::find($request->input('id'));
        $server->show = 0;
        if (!$server) {
            abort(500, '服务器不存在');
        }
        if (!ServerHysteria::create($server->toArray())) {
            abort(500, '复制失败');
        }

        return response([
            'data' => true
        ]);
    }

    private function notify($nodeName){
        $telegramService = new TelegramService();
        $chatID =config('v2board.telegram_group_id');
        $text = "🛠 #操作日志\n"
            . "———————————————\n"
            . "下述【节点】有更新：\n"
            . "`{$nodeName}`\n"
            . "请您更新订阅\n";
        $telegramService->sendMessage($chatID, $text,'markdown');
    }
}
