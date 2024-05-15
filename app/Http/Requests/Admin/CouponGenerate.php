<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CouponGenerate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'generate_count' => 'nullable|integer|max:500',
            'name' => 'required',
            'type' => 'required|in:1,2',
            'value' => 'required|integer',
            'started_at' => 'required|integer',
            'ended_at' => 'required|integer',
            'limit_use' => 'nullable|integer',
            'limit_use_with_user' => 'nullable|integer',
            'limit_plan_ids' => 'nullable|array',
            'limit_period' => 'nullable|array',
            'code' => '',
            'only_for_new_user' => 'required|in:0,1',
            'limit_inviter_ids' => 'nullable|string'
        ];
    }

    public function messages()
    {
        return [
            'generate_count.integer' => '生成数量必须为数字',
            'generate_count.max' => '生成数量最大为500个',
            'name.required' => '名称不能为空',
            'type.required' => '类型不能为空',
            'type.in' => '类型格式有误',
            'value.required' => '金额或比例不能为空',
            'value.integer' => '金额或比例格式有误',
            'started_at.required' => '开始时间不能为空',
            'started_at.integer' => '开始时间格式有误',
            'ended_at.required' => '结束时间不能为空',
            'ended_at.integer' => '结束时间格式有误',
            'limit_use.integer' => '最大使用次数格式有误',
            'limit_use_with_user.integer' => '限制用户使用次数格式有误',
            'limit_plan_ids.array' => '指定订阅格式有误',
            'limit_period.array' => '指定周期格式有误',
            'only_for_new_user.required' => '请指定是否限制新用户',
            'limit_inviter_ids.string' => '邀请人ID必须是字符串',
        ];
    }
}
