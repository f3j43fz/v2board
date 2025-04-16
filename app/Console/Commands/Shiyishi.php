<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class Shiyishi extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shishi';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        // 离线 IP 数据库路径（必须和下载命令保存的路径一致）
        $ipdbPath = resource_path('ipdata/qqwry.ipdb');
        // 全球 IPv4 地级市精度离线库（China：免费版，每周高级版，每日标准版，每日高级版，每日专业版，每日旗舰版）
        $city = new ipip\db\City($ipdbPath);
        var_dump($city->find('118.28.1.1', 'CN'));

    }
}
