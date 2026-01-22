<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RevisionAnswerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $question = $this->whenLoaded('question') ? $this->question : null;

        if (!$question) {
            return [];
        }

        return [
            'question' => new RevisionQuestionResource($question),
            'answer' => [
                'selected_option' => $this->selected_option,
                'correct_option' => $question->correct_option,
                'is_correct' => $this->is_correct,
                'time_taken_seconds' => $this->time_taken_seconds,
                'answered_at' => $this->answered_at?->toIso8601String(),
            ],
            'explanation' => $question->explanation,
            'guideline_reference' => $question->guideline_reference,
            'guideline_url' => $question->guideline_url,
            'guideline_excerpt' => $question->guideline_excerpt,
            'guideline_source' => $question->guideline_source,
            'references' => $question->references,
        ];
    }
}
