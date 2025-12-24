<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamAnswerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question_id' => $this->question_id,
            'question_order' => $this->question_order,
            'selected_option' => $this->selected_option,
            'is_flagged' => $this->is_flagged,
            'answered_at' => $this->answered_at?->toIso8601String(),
            'question' => new ExamQuestionResource($this->whenLoaded('question')),
        ];
    }
}
