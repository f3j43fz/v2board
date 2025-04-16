<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use ipip\db\District;
class Shiyishi extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'iptest';

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

        //项目地址：https://github.com/ipipdotnet/ipdb-php

        $ipdbPath = resource_path('ipdata/qqwry.ipdb');
        // 使用 use 导入后的类名，直接 new
        $city = new District($ipdbPath);
        var_dump($city->find('82.152.167.45', 'CN'));
    }
}
