<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    //protected $fillable = ['email', 'commission_balance'];

    /**
     * 定义 User 与 Plan 的一对一（或归属）关系
     * 这一步是必不可少的，否则 with('plan') 无法工作
     */
    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }
}
