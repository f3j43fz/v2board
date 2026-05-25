<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UserChangePassword extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'old_password' => 'required|string|max:64',
            'new_password' => 'required|string|min:8|max:64'
        ];
    }

    public function messages()
    {
        return [
            'old_password.required' => __('Old password cannot be empty'),
            'old_password.string'   => __('Password format is incorrect'),
            'old_password.max'      => __('Password is too long'),
            'new_password.required' => __('New password cannot be empty'),
            'new_password.string'   => __('Password format is incorrect'),
            'new_password.min'      => __('Password must be greater than 8 digits'),
            'new_password.max'      => __('Password is too long'),
        ];
    }
}
