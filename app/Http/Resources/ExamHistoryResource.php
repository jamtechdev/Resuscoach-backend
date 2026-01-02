<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Simplified version for history list view.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'started_at' => $this->started_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'status' => $this->status,
            'total_questions' => $this->total_questions,
            'score' => $this->status === 'completed' ? round($this->score ?? 0, 2) : null,
            'correct_count' => $this->status === 'completed' ? $this->correct_count : null,
            'percentage' => $this->status === 'completed' ? round($this->score ?? 0, 2) : null,
            'is_expired' => $this->isExpired(),
            'is_in_progress' => $this->isInProgress(),
        ];
    }
}

