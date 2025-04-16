<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use Exception;

class DownloadIPDB extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ipdb:download';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download the latest QQWry IP database and update the local file';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting IP database download...');

        // 下载地址
        $url = 'https://cdn.jsdelivr.net/npm/qqwry.ipdb/qqwry.ipdb';
        // 定义最终保存路径（建议存放在 resources/ipdata 目录下）
        $destinationPath = resource_path('ipdata/qqwry.ipdb');
        // 定义临时下载文件的路径
        $tempFilePath = $destinationPath . '.tmp';

        // 如果目录不存在则创建（确保目录有写入权限）
        $ipdataDir = resource_path('ipdata');
        if (!is_dir($ipdataDir)) {
            mkdir($ipdataDir, 0755, true);
        }

        $client = new Client();

        try {
            $response = $client->request('GET', $url, [
                'stream'  => true,
                'timeout' => 60, // 超时时间 60 秒
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->error('Failed to download IP database. HTTP Status: ' . $response->getStatusCode());
                return 1;
            }

            $body = $response->getBody();
            // 打开临时文件以写入
            $handle = fopen($tempFilePath, 'w');
            if ($handle === false) {
                $this->error('Unable to open temporary file for writing.');
                return 1;
            }

            // 每次读取 8KB 数据
            while (!$body->eof()) {
                fwrite($handle, $body->read(1024 * 8));
            }
            fclose($handle);

            // 简单检查：下载文件大小至少应大于 10MB（可根据实际情况调整）
            if (filesize($tempFilePath) < 10 * 1024 * 1024) {
                $this->error('Downloaded file is too small, aborting update.');
                unlink($tempFilePath);
                return 1;
            }

            // 下载成功后替换旧文件（先删除旧文件，确保原文件仅在下载成功后被替换）
            if (file_exists($destinationPath)) {
                unlink($destinationPath);
            }
            rename($tempFilePath, $destinationPath);
            $this->info('IP database updated successfully.');
        } catch (Exception $e) {
            $this->error('Error downloading IP database: ' . $e->getMessage());
            if (file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }
            return 1;
        }

        return 0;
    }
}
