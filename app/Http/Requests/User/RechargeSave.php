<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class RechargeSave extends FormRequest
{
    private $min = 5;
    private $max = 300;


    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $min = $this->min * 100;
        $max = $this->max * 100;

        return [
            'recharge_amount' => "required|numeric|min:{$min}|max:{$max}"
        ];
    }

    public function messages()
    {
        $currency = config('v2board.currency') == 'USD' ? "美元" : "元";
        return [
            'recharge_amount.max' => __('The recharge amount exceeds the limit of max currency', ['max' => $this->max, 'currency' => $currency]),
            'recharge_amount.min' => __('The recharge amount should larger than min currency', ['min' => $this->min, 'currency' => $currency])
        ];
    }
}
