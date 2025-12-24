<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    public function rules(): array
    {
        return [
            'topic' => ['sometimes', 'string', 'max:255'],
            'subtopic' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'topic.string' => 'Topic must be a valid string.',
            'subtopic.string' => 'Subtopic must be a valid string.',
        ];
    }
}
