<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class RechargeSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'recharge_amount' => 'required|numeric|min:1000|max:100000'
        ];
    }

    public function messages()
    {
        return [
            'recharge_amount.max' => __('The recharge amount exceeds the limit of 1000 yuan'),
            'recharge_amount.min' => __('The recharge amount should larger than 10 yuan')
        ];
    }
}
