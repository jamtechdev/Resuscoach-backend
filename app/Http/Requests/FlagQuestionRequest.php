<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FlagQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by controller
    }

    public function rules(): array
    {
        return [
            'question_id' => ['required', 'integer', 'exists:questions,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'question_id.required' => 'Question ID is required.',
            'question_id.exists' => 'The selected question does not exist.',
        ];
    }
}
