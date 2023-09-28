<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tokenrequest extends Model
{
    protected $table = 'v2_tokenrequest'; // 数据库表名
    protected $dateFormat = 'U';

    protected $fillable = [
        'token',
        'ip',
        'requested_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
    ];

    public $timestamps = false;
}
