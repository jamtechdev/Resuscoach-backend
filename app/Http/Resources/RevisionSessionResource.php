<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RevisionSessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'session_id' => $this->id,
            'attempt_id' => $this->attempt_id,
            'status' => $this->status,
            'selected_topics' => $this->selected_topics,
            'started_at' => $this->started_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'paused_at' => $this->paused_at?->toIso8601String(),
            'total_questions' => $this->total_questions,
            'questions_answered' => $this->questions_answered,
            'correct_count' => $this->correct_count,
            'current_question_id' => $this->current_question_id,
            'current_question_index' => $this->current_question_index,
            'score' => $this->total_questions > 0
                ? round(($this->correct_count / $this->total_questions) * 100, 2)
                : 0,
        ];
    }
}
