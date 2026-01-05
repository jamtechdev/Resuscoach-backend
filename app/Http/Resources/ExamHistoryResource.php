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
        $isCompleted = $this->status === 'completed';
        $score = $isCompleted ? round($this->score ?? 0, 2) : null;
        $passed = $isCompleted ? $this->isPassed() : null;

        // Get topics and subtopics
        $topicsData = $this->getTopicsAndSubtopics();

        return [
            'id' => $this->id,
            'started_at' => $this->started_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'status' => $this->status,
            'total_questions' => $this->total_questions,
            'score' => $score,
            'correct_count' => $isCompleted ? $this->correct_count : null,
            'percentage' => $score,
            'passed' => $passed,
            'result' => $passed !== null ? ($passed ? 'passed' : 'failed') : null,
            'topics' => $topicsData['topics'],
            'subtopics' => $topicsData['subtopics'],
            'is_expired' => $this->isExpired(),
            'is_in_progress' => $this->isInProgress(),
        ];
    }
}
