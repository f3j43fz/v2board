<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use App\Models\MailLog;

class SendEmailJob2 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $emails;

    public $tries = 5;
    public $timeout = 60;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($emails, $queue = 'send_email')
    {
        $this->onQueue($queue);
        $this->emails = $emails;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (config('v2board.email_host')) {
            Config::set('mail.host', config('v2board.email_host', env('mail.host')));
            Config::set('mail.port', config('v2board.email_port', env('mail.port')));
            Config::set('mail.encryption', config('v2board.email_encryption', env('mail.encryption')));
            Config::set('mail.username', config('v2board.email_username', env('mail.username')));
            Config::set('mail.password', config('v2board.email_password', env('mail.password')));
            Config::set('mail.from.address', config('v2board.email_from_address', env('mail.from.address')));
            Config::set('mail.from.name', config('v2board.app_name', 'V2Board'));
        }

        foreach ($this->emails as $params) {
            $email = $params['email'];
            $subject = $params['subject'];
            $params['template_name'] = 'mail.' . config('v2board.email_template', 'default') . '.' . $params['template_name'];

            try {
                Mail::send(
                    $params['template_name'],
                    $params['template_value'],
                    function ($message) use ($email, $subject) {
                        $message->to($email)->subject($subject);
                    }
                );
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }

            $log = [
                'email' => $params['email'],
                'subject' => $params['subject'],
                'template_name' => $params['template_name'],
                'error' => isset($error) ? $error : null
            ];

            MailLog::create($log);
            $log['config'] = config('mail');
        }

        return $log;
    }
}
