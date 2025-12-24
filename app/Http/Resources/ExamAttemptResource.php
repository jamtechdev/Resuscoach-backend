<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamAttemptResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isCompleted = $this->status === 'completed';
        $showResults = $isCompleted || $request->routeIs('api.exams.results');

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'started_at' => $this->started_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'status' => $this->status,
            'total_questions' => $this->total_questions,
            'remaining_seconds' => $this->remaining_seconds,
            'is_expired' => $this->isExpired(),
            'is_in_progress' => $this->isInProgress(),

            // Results (only shown when completed or on results endpoint)
            'score' => $showResults ? $this->score : null,
            'correct_count' => $showResults ? $this->correct_count : null,
            'percentage' => $showResults ? round($this->score ?? 0, 2) : null,

            // Questions (extracted from answers) and answers
            'questions' => $this->when(
                $this->relationLoaded('answers'),
                function () {
                    $questions = $this->answers
                        ->map(fn($answer) => $answer->question)
                        ->filter()
                        ->unique('id')
                        ->sortBy(fn($question, $key) => $this->answers->firstWhere('question_id', $question->id)->question_order ?? 0)
                        ->values();
                    return ExamQuestionResource::collection($questions);
                }
            ),
            'answers' => ExamAnswerResource::collection($this->whenLoaded('answers')),

            // User info
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
