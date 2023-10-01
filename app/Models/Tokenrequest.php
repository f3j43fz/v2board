<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tokenrequest extends Model
{
    protected $table = 'v2_tokenrequest'; // 数据库表名
    protected $dateFormat = 'U';

    protected $fillable = [
        'user_id',
        'ip',
        'requested_at',
        'location',
    ];

    protected $casts = [
        'requested_at' => 'timestamp',
    ];

    public $timestamps = false;
}
