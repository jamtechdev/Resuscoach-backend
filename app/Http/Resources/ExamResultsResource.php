<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamResultsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $answers = $this->answers()->with('question')->get();
        $topicBreakdown = $this->calculateTopicBreakdown($answers);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'started_at' => $this->started_at->toIso8601String(),
            'completed_at' => $this->completed_at->toIso8601String(),
            'status' => $this->status,
            'total_questions' => $this->total_questions,
            'correct_count' => $this->correct_count,
            'incorrect_count' => $this->total_questions - $this->correct_count,
            'score' => round($this->score ?? 0, 2),
            'percentage' => round($this->score ?? 0, 2),

            // Detailed answers with correct answers and explanations
            'answers' => ExamAnswerWithDetailsResource::collection($answers),

            // Topic-wise performance
            'topic_breakdown' => $topicBreakdown,

            // User info
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }

    /**
     * Calculate topic-wise performance breakdown.
     */
    private function calculateTopicBreakdown($answers): array
    {
        $breakdown = [];

        foreach ($answers as $answer) {
            $topic = $answer->question->topic ?? 'Unknown';

            if (!isset($breakdown[$topic])) {
                $breakdown[$topic] = [
                    'topic' => $topic,
                    'total' => 0,
                    'correct' => 0,
                    'incorrect' => 0,
                ];
            }

            $breakdown[$topic]['total']++;
            if ($answer->is_correct) {
                $breakdown[$topic]['correct']++;
            } else {
                $breakdown[$topic]['incorrect']++;
            }
        }

        // Calculate percentages
        foreach ($breakdown as &$topicData) {
            $topicData['percentage'] = $topicData['total'] > 0
                ? round(($topicData['correct'] / $topicData['total']) * 100, 2)
                : 0;
        }

        return array_values($breakdown);
    }
}
