<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by controller
    }

    public function rules(): array
    {
        return [
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'selected_option' => [
                'required',
                Rule::in(['A', 'B', 'C', 'D', 'E']),
            ],
            // Optional: frontend sends remaining time so backend can persist it for pause/resume
            'remaining_seconds' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'question_id.required' => 'Question ID is required.',
            'question_id.exists' => 'The selected question does not exist.',
            'selected_option.required' => 'Please select an answer option.',
            'selected_option.in' => 'Selected option must be A, B, C, D, or E.',
        ];
    }
}
