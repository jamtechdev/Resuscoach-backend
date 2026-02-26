<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CoachingRespondRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    public function rules(): array
    {
        $stepNumber = $this->input('step_number');
        $minResponse = ($stepNumber === 1) ? 1 : 10;

        return [
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'step_number' => ['required', 'integer', 'min:1', 'max:5'],
            'response' => ['required', 'string', 'min:' . $minResponse, 'max:2000'],
            'response_type' => ['sometimes', 'string', Rule::in(['text', 'voice'])],
        ];
    }

    public function messages(): array
    {
        return [
            'question_id.required' => 'Question ID is required.',
            'question_id.exists' => 'The selected question does not exist.',
            'step_number.required' => 'Step number is required.',
            'step_number.min' => 'Step number must be at least 1.',
            'step_number.max' => 'Step number must be at most 5.',
            'response.required' => 'Response is required.',
            'response.min' => 'Response must be at least :min characters.',
            'response.max' => 'Response must not exceed 2000 characters.',
            'response_type.in' => 'Response type must be either text or voice.',
        ];
    }
}
