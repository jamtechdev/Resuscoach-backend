<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TextToSpeechRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:1', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'text.required' => 'Text is required.',
            'text.max' => 'Text must not exceed 4096 characters.',
        ];
    }
}
