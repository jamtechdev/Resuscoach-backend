<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamAnswerWithDetailsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $question = $this->question;

        return [
            'id' => $this->id,
            'question_id' => $this->question_id,
            'question_order' => $this->question_order,
            'selected_option' => $this->selected_option,
            'is_correct' => $this->is_correct,
            'is_flagged' => $this->is_flagged,
            'answered_at' => $this->answered_at?->toIso8601String(),

            // Question details with correct answer and explanation
            'question' => [
                'id' => $question->id,
                'stem' => $question->stem,
                'options' => [
                    'A' => $question->option_a,
                    'B' => $question->option_b,
                    'C' => $question->option_c,
                    'D' => $question->option_d,
                    'E' => $question->option_e,
                ],
                'correct_option' => $question->correct_option,
                'explanation' => $question->explanation,
                'topic' => $question->topic,
                'subtopic' => $question->subtopic,
                'guideline_reference' => $question->guideline_reference,
                'guideline_url' => $question->guideline_url,
                'references' => $question->references,
                'has_image' => $question->has_image,
                'image_url' => $question->image_url,
            ],
        ];
    }
}
