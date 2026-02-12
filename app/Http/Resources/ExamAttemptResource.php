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
            'current_question_index' => $this->when(isset($this->current_question_index), $this->current_question_index),
            'is_expired' => $this->isExpired(),
            'is_in_progress' => $this->isInProgress(),

            // Exam instructions
            'instructions' => [
                'title' => 'Exam Instructions',
                'duration' => 'You have 30 minutes to complete this exam.',
                'total_questions' => "This exam contains {$this->total_questions} questions.",
                'rules' => [
                    'Read each question carefully before selecting your answer.',
                    'You can navigate between questions at any time.',
                    'You can change your answers before submitting the exam.',
                    'Once you submit the exam, you cannot make any changes.',
                    'The exam will automatically submit when the time expires.',
                    'Each question has only one correct answer.',
                    'Select the best answer from options A, B, C, D, or E.',
                ],
                'tips' => [
                    'Manage your time wisely - you have approximately 1 minute per question.',
                    'Answer all questions - unanswered questions will be marked as incorrect.',
                ],
            ],

            // Results (only shown when completed or on results endpoint)
            'score' => $showResults ? $this->score : null,
            'correct_count' => $showResults ? $this->correct_count : null,
            'percentage' => $showResults ? round($this->score ?? 0, 2) : null,
            'passed' => $showResults && $isCompleted ? $this->isPassed() : null,
            'result' => $showResults && $isCompleted ? ($this->isPassed() ? 'passed' : 'failed') : null,

            // Questions (extracted from answers, sorted by question_order) and answers
            'questions' => $this->when(
                $this->relationLoaded('answers'),
                function () {
                    // Sort answers by question_order, then extract questions
                    $sortedAnswers = $this->answers->sortBy('question_order')->values();
                    $questions = $sortedAnswers
                        ->map(fn($answer) => $answer->question)
                        ->filter()
                        ->values();
                    return ExamQuestionResource::collection($questions);
                }
            ),

            // Answers sorted by question_order for easier frontend consumption
            'answers' => $this->when(
                $this->relationLoaded('answers'),
                function () {
                    return ExamAnswerResource::collection(
                        $this->answers->sortBy('question_order')->values()
                    );
                }
            ),

             // Grid navigation summary (for building question grid view)
            'grid_summary' => $this->when(
                $this->relationLoaded('answers'),
                function () {
                    $sortedAnswers = $this->answers->sortBy('question_order')->values();

                    return [
                        'total_questions' => $this->total_questions,
                        'answered_count' => $sortedAnswers->whereNotNull('selected_option')->count(),
                        'flagged_count' => $sortedAnswers->where('is_flagged', true)->count(),
                        'flagged_question_ids' => $sortedAnswers
                            ->where('is_flagged', true)
                            ->pluck('question_id')
                            ->toArray(),
                        'answered_question_ids' => $sortedAnswers
                            ->whereNotNull('selected_option')
                            ->pluck('question_id')
                            ->toArray(),
                        'question_status' => $sortedAnswers->map(function ($answer) {
                            return [
                                'question_id' => $answer->question_id,
                                'question_order' => $answer->question_order,
                                'is_answered' => !is_null($answer->selected_option),
                                'is_flagged' => $answer->is_flagged,
                            ];
                        })->values()->toArray(),
                    ];
                }
            ),

            // User info
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
