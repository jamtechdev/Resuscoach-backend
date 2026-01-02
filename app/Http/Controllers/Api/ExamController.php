<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlagQuestionRequest;
use App\Http\Requests\StartExamRequest;
use App\Http\Requests\SubmitAnswerRequest;
use App\Http\Requests\SubmitExamRequest;
use App\Http\Resources\ExamAttemptResource;
use App\Http\Resources\ExamHistoryResource;
use App\Http\Resources\ExamResultsResource;
use App\Models\ExamAttempt;
use App\Models\ExamAnswer;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExamController extends Controller
{
    /**
     * Get available topics and subtopics for exam filtering.
     * Returns all active topics with their subtopics and question counts.
     * This is available to all authenticated users to help them filter exams.
     */
    public function getTopics(): JsonResponse
    {
        try {
            // Get all active questions grouped by topic
            $topics = Question::where('is_active', true)
                ->get()
                ->groupBy('topic')
                ->map(function ($questions, $topic) {
                    // Get unique subtopics for this topic
                    // Filter out null, empty strings, and whitespace-only values
                    $subtopics = $questions
                        ->whereNotNull('subtopic')
                        ->pluck('subtopic')
                        ->map(fn($subtopic) => trim($subtopic))
                        ->filter(fn($subtopic) => !empty($subtopic))
                        ->unique()
                        ->values()
                        ->toArray();

                    return [
                        'topic' => $topic,
                        'subtopics' => $subtopics, // Will be empty array [] if no subtopics
                        'question_count' => $questions->count(),
                    ];
                })
                ->values()
                ->sortBy('topic'); // Sort alphabetically for better UX

            return response()->json([
                'success' => true,
                'data' => $topics,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch topics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch topics.',
            ], 500);
        }
    }

    /**
     * Start a new exam attempt.
     *
     * Randomly selects up to 40 active questions (or all available if less than 40)
     * based on optional topic/subtopic filters and creates an exam attempt.
     */
    public function start(StartExamRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Check if user has an in-progress exam
            $inProgressExam = ExamAttempt::where('user_id', $user->id)
                ->where('status', 'in_progress')
                ->first();

            if ($inProgressExam && !$inProgressExam->isExpired()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have an exam in progress.',
                    'data' => [
                        'exam_id' => $inProgressExam->id,
                    ],
                ], 409);
            }

            // Build query for questions with optional topic/subtopic filtering
            $query = Question::where('is_active', true);

            // Filter by topic if provided
            if ($request->has('topic') && !empty($request->topic)) {
                $query->where('topic', $request->topic);
            }

            // Filter by subtopic if provided (only if topic is also provided)
            if ($request->has('subtopic') && !empty($request->subtopic)) {
                $query->where('subtopic', $request->subtopic);
            }

            // Get up to 40 random questions (or all available if less than 40)
            $questions = $query->inRandomOrder()
                ->limit(40)
                ->get();

            // Use actual count of questions retrieved
            $questionCount = $questions->count();

            DB::beginTransaction();

            try {
                // Create exam attempt
                $examAttempt = ExamAttempt::create([
                    'user_id' => $user->id,
                    'started_at' => now(),
                    'expires_at' => now()->addMinutes(45), // 45 minutes = 2700 seconds
                    'total_questions' => $questionCount, // Use actual count of questions available
                    'status' => 'in_progress',
                ]);

                // Create exam answer records for all questions
                $questionOrder = 1;
                foreach ($questions as $question) {
                    ExamAnswer::create([
                        'attempt_id' => $examAttempt->id,
                        'question_id' => $question->id,
                        'question_order' => $questionOrder++,
                        'is_flagged' => false,
                    ]);
                }

                DB::commit();

                // Load relationships for response
                $examAttempt->load(['answers.question']);

                return response()->json([
                    'success' => true,
                    'message' => 'Exam started successfully.',
                    'data' => new ExamAttemptResource($examAttempt),
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Failed to start exam', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start exam. Please try again.',
            ], 500);
        }
    }

    /**
     * Get exam details.
     *
     * Returns exam information with questions and current answers.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            $examAttempt = ExamAttempt::with(['answers.question', 'user'])
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Check if exam is expired and update status if needed
            if ($examAttempt->isExpired() && $examAttempt->status === 'in_progress') {
                $this->autoSubmitExam($examAttempt);
                $examAttempt->refresh();
            }

            return response()->json([
                'success' => true,
                'data' => new ExamAttemptResource($examAttempt),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exam not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to fetch exam', [
                'exam_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch exam details.',
            ], 500);
        }
    }

    /**
     * Submit or update an answer for a question.
     */
    public function submitAnswer(SubmitAnswerRequest $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            $examAttempt = ExamAttempt::where('id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Check if exam is still in progress
            if ($examAttempt->status !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot submit answer. Exam is already completed or abandoned.',
                ], 403);
            }

            // Check if exam is expired
            if ($examAttempt->isExpired()) {
                $this->autoSubmitExam($examAttempt);
                return response()->json([
                    'success' => false,
                    'message' => 'Exam time has expired. Your exam has been automatically submitted.',
                ], 403);
            }

            // Find the answer record
            $examAnswer = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('question_id', $request->question_id)
                ->firstOrFail();

            // Get the question to check correct answer
            $question = Question::findOrFail($request->question_id);

            // Update answer
            $examAnswer->update([
                'selected_option' => $request->selected_option,
                'is_correct' => $request->selected_option === $question->correct_option,
                'answered_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Answer submitted successfully.',
                'data' => new \App\Http\Resources\ExamAnswerResource($examAnswer->load('question')),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exam or question not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to submit answer', [
                'exam_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit answer. Please try again.',
            ], 500);
        }
    }

    /**
     * Toggle flag on a question.
     */
    public function flagQuestion(FlagQuestionRequest $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            $examAttempt = ExamAttempt::where('id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Check if exam is still in progress
            if ($examAttempt->status !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot flag question. Exam is already completed or abandoned.',
                ], 403);
            }

            // Find the answer record
            $examAnswer = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('question_id', $request->question_id)
                ->firstOrFail();

            // Toggle flag
            $examAnswer->update([
                'is_flagged' => !$examAnswer->is_flagged,
            ]);

            return response()->json([
                'success' => true,
                'message' => $examAnswer->is_flagged ? 'Question flagged.' : 'Question unflagged.',
                'data' => [
                    'question_id' => $examAnswer->question_id,
                    'is_flagged' => $examAnswer->is_flagged,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exam or question not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to flag question', [
                'exam_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to flag question. Please try again.',
            ], 500);
        }
    }

    /**
     * Submit the exam (end exam early).
     */
    public function submit(SubmitExamRequest $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            $examAttempt = ExamAttempt::where('id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Check if exam is already completed
            if ($examAttempt->status === 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Exam is already completed.',
                ], 403);
            }

            // Submit the exam
            $this->submitExam($examAttempt);

            // Load relationships for response
            $examAttempt->load(['answers.question', 'user']);

            return response()->json([
                'success' => true,
                'message' => 'Exam submitted successfully.',
                'data' => new ExamAttemptResource($examAttempt),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exam not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to submit exam', [
                'exam_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit exam. Please try again.',
            ], 500);
        }
    }

    /**
     * Get flagged questions for an exam.
     * Returns a list of flagged questions for easier navigation.
     */
    public function getFlaggedQuestions(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            $examAttempt = ExamAttempt::where('id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Check if exam is still in progress
            if ($examAttempt->status !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only view flagged questions for in-progress exams.',
                ], 403);
            }

            // Get flagged answers with questions
            $flaggedAnswers = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('is_flagged', true)
                ->with('question')
                ->orderBy('question_order')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'flagged_count' => $flaggedAnswers->count(),
                    'flagged_questions' => \App\Http\Resources\ExamAnswerResource::collection($flaggedAnswers),
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exam not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to fetch flagged questions', [
                'exam_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch flagged questions.',
            ], 500);
        }
    }

    /**
     * Get exam results with detailed breakdown.
     */
    public function results(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            $examAttempt = ExamAttempt::with(['answers.question', 'user'])
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Only show results if exam is completed
            if ($examAttempt->status !== 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Exam results are only available after the exam is completed.',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => new ExamResultsResource($examAttempt),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exam not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to fetch exam results', [
                'exam_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch exam results.',
            ], 500);
        }
    }

    /**
     * Submit the exam and calculate score.
     */
    private function submitExam(ExamAttempt $examAttempt): void
    {
        // Calculate score
        $correctCount = ExamAnswer::where('attempt_id', $examAttempt->id)
            ->where('is_correct', true)
            ->count();

        $score = ($correctCount / $examAttempt->total_questions) * 100;

        // Update exam attempt
        $examAttempt->update([
            'status' => 'completed',
            'completed_at' => now(),
            'correct_count' => $correctCount,
            'score' => round($score, 2),
        ]);
    }

    /**
     * Get user's exam history with optional filters.
     *
     * Query parameters:
     * - status: Filter by status (in_progress, completed, abandoned)
     * - per_page: Number of items per page (default: 20)
     * - page: Page number (default: 1)
     */
    public function history(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $query = ExamAttempt::where('user_id', $user->id);

            // Filter by status if provided
            if ($request->has('status') && in_array($request->status, ['in_progress', 'completed', 'abandoned'])) {
                $query->where('status', $request->status);
            }

            // Filter by date range if provided
            if ($request->has('start_date')) {
                $query->whereDate('started_at', '>=', $request->start_date);
            }

            if ($request->has('end_date')) {
                $query->whereDate('started_at', '<=', $request->end_date);
            }

            // Order by most recent first
            $query->orderBy('started_at', 'desc');

            // Pagination
            $perPage = $request->get('per_page', 20);
            $perPage = min(max(1, (int)$perPage), 100); // Limit between 1 and 100
            $exams = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => ExamHistoryResource::collection($exams->items()),
                'meta' => [
                    'current_page' => $exams->currentPage(),
                    'last_page' => $exams->lastPage(),
                    'per_page' => $exams->perPage(),
                    'total' => $exams->total(),
                    'from' => $exams->firstItem(),
                    'to' => $exams->lastItem(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch exam history', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch exam history.',
            ], 500);
        }
    }

    /**
     * Get user's exam statistics and summary.
     * Returns comprehensive statistics about the user's exam performance.
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Get all user's exam attempts
            $allExams = ExamAttempt::where('user_id', $user->id);
            $completedExams = ExamAttempt::where('user_id', $user->id)
                ->where('status', 'completed');
            $inProgressExams = ExamAttempt::where('user_id', $user->id)
                ->where('status', 'in_progress');
            $abandonedExams = ExamAttempt::where('user_id', $user->id)
                ->where('status', 'abandoned');

            // Basic counts
            $totalExams = $allExams->count();
            $completedCount = $completedExams->count();
            $inProgressCount = $inProgressExams->count();
            $abandonedCount = $abandonedExams->count();

            // Score statistics (only for completed exams)
            $avgScore = $completedExams->avg('score');
            $bestScore = $completedExams->max('score');
            $worstScore = $completedExams->min('score');

            // Get best and worst exam attempts
            $bestExam = $completedExams->orderBy('score', 'desc')->first();
            $worstExam = $completedExams->orderBy('score', 'asc')->first();

            // Recent activity (last 5 exams)
            $recentExams = $allExams->orderBy('started_at', 'desc')
                ->limit(5)
                ->get();

            // Performance trend (last 10 completed exams)
            $recentScores = $completedExams->orderBy('completed_at', 'desc')
                ->limit(10)
                ->pluck('score')
                ->reverse()
                ->values();

            // Calculate improvement trend
            $improvementTrend = null;
            if ($recentScores->count() >= 2) {
                $firstHalf = $recentScores->take(ceil($recentScores->count() / 2))->avg();
                $secondHalf = $recentScores->skip(floor($recentScores->count() / 2))->avg();
                $improvementTrend = $secondHalf > $firstHalf ? 'improving' : ($secondHalf < $firstHalf ? 'declining' : 'stable');
            }

            // Topic-wise performance (if we have completed exams with answers)
            $topicPerformance = [];
            if ($completedCount > 0) {
                $topicStats = DB::table('exam_attempts')
                    ->join('exam_answers', 'exam_attempts.id', '=', 'exam_answers.attempt_id')
                    ->join('questions', 'exam_answers.question_id', '=', 'questions.id')
                    ->where('exam_attempts.user_id', $user->id)
                    ->where('exam_attempts.status', 'completed')
                    ->select(
                        'questions.topic',
                        DB::raw('COUNT(*) as total_questions'),
                        DB::raw('SUM(CASE WHEN exam_answers.is_correct = 1 THEN 1 ELSE 0 END) as correct_answers')
                    )
                    ->groupBy('questions.topic')
                    ->get();

                foreach ($topicStats as $stat) {
                    $topicPerformance[] = [
                        'topic' => $stat->topic ?? 'Unknown',
                        'total_questions' => (int)$stat->total_questions,
                        'correct_answers' => (int)$stat->correct_answers,
                        'percentage' => $stat->total_questions > 0
                            ? round(($stat->correct_answers / $stat->total_questions) * 100, 2)
                            : 0,
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => [
                        'total_exams' => $totalExams,
                        'completed_exams' => $completedCount,
                        'in_progress_exams' => $inProgressCount,
                        'abandoned_exams' => $abandonedCount,
                    ],
                    'performance' => [
                        'average_score' => $avgScore ? round($avgScore, 2) : null,
                        'best_score' => $bestScore ? round($bestScore, 2) : null,
                        'worst_score' => $worstScore ? round($worstScore, 2) : null,
                        'improvement_trend' => $improvementTrend,
                    ],
                    'best_exam' => $bestExam ? [
                        'id' => $bestExam->id,
                        'score' => round($bestExam->score ?? 0, 2),
                        'completed_at' => $bestExam->completed_at?->toIso8601String(),
                    ] : null,
                    'worst_exam' => $worstExam ? [
                        'id' => $worstExam->id,
                        'score' => round($worstExam->score ?? 0, 2),
                        'completed_at' => $worstExam->completed_at?->toIso8601String(),
                    ] : null,
                    'topic_performance' => $topicPerformance,
                    'recent_scores' => $recentScores->map(fn($score) => round($score, 2))->toArray(),
                    'recent_exams' => ExamHistoryResource::collection($recentExams),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch exam statistics', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch exam statistics.',
            ], 500);
        }
    }

    /**
     * Check if user has an in-progress exam.
     */
    public function checkInProgress(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $inProgressExam = ExamAttempt::where('user_id', $user->id)
                ->where('status', 'in_progress')
                ->first();

            if ($inProgressExam && !$inProgressExam->isExpired()) {
                return response()->json([
                    'success' => true,
                    'has_in_progress' => true,
                    'data' => new ExamAttemptResource($inProgressExam->load(['answers.question'])),
                ]);
            }

            return response()->json([
                'success' => true,
                'has_in_progress' => false,
                'data' => null,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to check in-progress exam', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to check exam status.',
            ], 500);
        }
    }

    /**
     * Auto-submit exam when time expires.
     */
    private function autoSubmitExam(ExamAttempt $examAttempt): void
    {
        if ($examAttempt->status === 'in_progress') {
            $this->submitExam($examAttempt);
        }
    }
}
