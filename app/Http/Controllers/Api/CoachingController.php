<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CoachingRespondRequest;
use App\Models\CoachingSession;
use App\Models\CoachingDialogue;
use App\Models\CoachingSummary;
use App\Models\ExamAttempt;
use App\Models\ExamAnswer;
use App\Models\Question;
use App\Services\OpenAICoachingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CoachingController extends Controller
{
    protected OpenAICoachingService $coachingService;

    /** Cache-Control so step responses are never cached (avoids wrong resource on live/CDN). */
    private const NO_CACHE_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ];

    public function __construct(OpenAICoachingService $coachingService)
    {
        $this->coachingService = $coachingService;
    }

    /**
     * Return JSON with no-cache headers. Prevents CDN/browser from serving stale step data
     * (e.g. resource from another question) on live.
     */
    private function noCacheJson(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->withHeaders(self::NO_CACHE_HEADERS);
    }

    /**
     * Log exception and return a consistent JSON error response for better API experience.
     */
    private function logAndRespond(\Throwable $e, string $message, int $status = 500, array $context = []): JsonResponse
    {
        $logContext = array_merge($context, [
            'error' => $e->getMessage(),
            'exception' => get_class($e),
        ]);
        if (!app()->environment('production')) {
            $logContext['trace'] = $e->getTraceAsString();
        }

        Log::error($message, $logContext);

        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }

    /**
     * Start a coaching session from a completed exam attempt.
     *
     * Selects incorrect and flagged questions (targeting ~10 questions)
     * and creates a coaching session.
     */
    public function start(Request $request, int $examId): JsonResponse
    {
        try {
            $user = $request->user();

            // Get the exam attempt
            $examAttempt = ExamAttempt::where('id', $examId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Check if exam is completed
            if ($examAttempt->status !== 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Coaching can only be started for completed exams.',
                ], 403);
            }

            // If a coaching session already exists for this exam, return it so user can continue (allow re-entry / multiple visits)
            $existingSession = CoachingSession::where('attempt_id', $examAttempt->id)->first();
            if ($existingSession) {
                $incorrectAnswers = ExamAnswer::where('attempt_id', $examAttempt->id)
                    ->where('is_correct', false)
                    ->pluck('question_id')->toArray();
                $flaggedQuestionIds = ExamAnswer::where('attempt_id', $examAttempt->id)
                    ->where('is_flagged', true)
                    ->pluck('question_id')->toArray();
                $questionIds = $incorrectAnswers;
                foreach ($flaggedQuestionIds as $flaggedId) {
                    if (!in_array($flaggedId, $questionIds)) {
                        $questionIds[] = $flaggedId;
                    }
                }
                $questionIds = array_slice($questionIds, 0, 10);
                return response()->json([
                    'success' => true,
                    'message' => 'Coaching session already exists for this exam. You can continue where you left off.',
                    'data' => [
                        'session_id' => $existingSession->id,
                        'attempt_id' => $examAttempt->id,
                        'questions_to_review' => count($questionIds) ?: $existingSession->questions_reviewed,
                        'question_ids' => $questionIds,
                        'started_at' => $existingSession->started_at->toIso8601String(),
                    ],
                ], 200);
            }

            // Get incorrect and flagged questions
            $incorrectAnswers = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('is_correct', false)
                ->with('question')
                ->get();

            $flaggedAnswers = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('is_flagged', true)
                ->with('question')
                ->get();

            // Combine and deduplicate (prioritize incorrect over flagged)
            $questionIds = $incorrectAnswers->pluck('question_id')->toArray();
            $flaggedQuestionIds = $flaggedAnswers->pluck('question_id')->toArray();

            // Add flagged questions that aren't already in incorrect list
            foreach ($flaggedQuestionIds as $flaggedId) {
                if (!in_array($flaggedId, $questionIds)) {
                    $questionIds[] = $flaggedId;
                }
            }

            // Limit to ~10 questions (or all if less than 10)
            $questionIds = array_slice($questionIds, 0, 10);

            if (empty($questionIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No incorrect or flagged questions found. Coaching requires questions to review.',
                ], 400);
            }

            DB::beginTransaction();

            try {
                // Create coaching session
                $coachingSession = CoachingSession::create([
                    'attempt_id' => $examAttempt->id,
                    'user_id' => $user->id,
                    'started_at' => now(),
                    'status' => 'in_progress',
                    'questions_reviewed' => count($questionIds),
                    'current_question_id' => !empty($questionIds) ? $questionIds[0] : null, // Set first question as current
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Coaching session started successfully.',
                    'data' => [
                        'session_id' => $coachingSession->id,
                        'attempt_id' => $examAttempt->id,
                        'questions_to_review' => count($questionIds),
                        'question_ids' => $questionIds,
                        'started_at' => $coachingSession->started_at->toIso8601String(),
                    ],
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exam not found.',
            ], 404);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to start coaching session. Please try again.', 500, [
                'exam_id' => $examId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Get coaching session details.
     */
    public function show(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::with(['attempt', 'dialogues.question', 'summary'])
                ->where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Get questions being reviewed in this session
            $examAttempt = $session->attempt;
            $incorrectAnswers = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('is_correct', false)
                ->with('question')
                ->get();

            $flaggedAnswers = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('is_flagged', true)
                ->with('question')
                ->get();

            $questionIds = $incorrectAnswers->pluck('question_id')->toArray();
            $flaggedQuestionIds = $flaggedAnswers->pluck('question_id')->toArray();

            foreach ($flaggedQuestionIds as $flaggedId) {
                if (!in_array($flaggedId, $questionIds)) {
                    $questionIds[] = $flaggedId;
                }
            }
            $questionIds = array_slice($questionIds, 0, 10);

            // Calculate elapsed time
            $elapsedSeconds = $session->total_duration_seconds;
            if ($session->status === 'in_progress' && $session->started_at) {
                $elapsedSeconds += now()->diffInSeconds($session->started_at);
                if ($session->paused_at) {
                    $elapsedSeconds -= now()->diffInSeconds($session->paused_at);
                }
            }

            $maxDurationSeconds = (int) config('coaching.max_duration_seconds', 1800);
            $remainingSeconds = max(0, $maxDurationSeconds - $elapsedSeconds);

            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $session->id,
                    'attempt_id' => $session->attempt_id,
                    'status' => $session->status,
                    'started_at' => $session->started_at->toIso8601String(),
                    'paused_at' => $session->paused_at?->toIso8601String(),
                    'completed_at' => $session->completed_at?->toIso8601String(),
                    'elapsed_seconds' => $elapsedSeconds,
                    'total_duration_seconds' => $session->total_duration_seconds,
                    'max_duration_seconds' => $maxDurationSeconds,
                    'remaining_seconds' => $remainingSeconds,
                    'questions_reviewed' => $session->questions_reviewed,
                    'questions_to_review' => $questionIds,
                    'current_question_id' => $session->current_question_id,
                    'has_summary' => $session->summary !== null,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coaching session not found.',
            ], 404);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to fetch coaching session.', 500, [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Get all questions for Step 1 (initial load).
     * Returns all questions with their data so frontend can save them locally.
     */
    public function getAllQuestions(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Allow when in_progress or paused so user can load page when returning / after "Start AI Coaching"
            if (!in_array($session->status, ['in_progress', 'paused'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Coaching session has ended.',
                ], 403);
            }

            $examAttempt = $session->attempt;

            // Get questions being reviewed in this session
            $incorrectAnswers = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('is_correct', false)
                ->with('question')
                ->get();

            $flaggedAnswers = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('is_flagged', true)
                ->with('question')
                ->get();

            $questionIds = $incorrectAnswers->pluck('question_id')->toArray();
            $flaggedQuestionIds = $flaggedAnswers->pluck('question_id')->toArray();

            foreach ($flaggedQuestionIds as $flaggedId) {
                if (!in_array($flaggedId, $questionIds)) {
                    $questionIds[] = $flaggedId;
                }
            }
            $questionIds = array_slice($questionIds, 0, 10);

            // Get all questions with their answers
            $allQuestions = [];
            foreach ($questionIds as $qId) {
                $question = Question::find($qId);
                $examAnswer = ExamAnswer::where('attempt_id', $examAttempt->id)
                    ->where('question_id', $qId)
                    ->first();

                if ($question && $examAnswer) {
                    // No dialogue created here — first getCurrentStep hit returns step 1 (correct answer + reference)
                    $allQuestions[] = [
                        'question_id' => $question->id,
                        'question' => [
                            'id' => $question->id,
                            'stem' => $question->stem,
                            'scenario' => $question->scenario,
                            'options' => [
                                'A' => $question->option_a,
                                'B' => $question->option_b,
                                'C' => $question->option_c,
                                'D' => $question->option_d,
                                'E' => $question->option_e,
                            ],
                        ],
                        'user_answer' => $examAnswer->selected_option,
                        'is_flagged' => $examAnswer->is_flagged,
                        'is_correct' => $examAnswer->is_correct,
                        'step_number' => 1,
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $sessionId,
                    'questions' => $allQuestions,
                    'total_questions' => count($allQuestions),
                    'message' => 'All questions loaded. Frontend can now manage navigation and timer.',
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coaching session not found.',
            ], 404);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to load questions.', 500, [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Get the current step for a question in the coaching session.
     * Returns the AI prompt for the current step.
     */
    public function getCurrentStep(Request $request, int $sessionId, int $questionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Allow step when in_progress or paused so user can still view content and end session
            if (!in_array($session->status, ['in_progress', 'paused'], true)) {
                return $this->noCacheJson([
                    'success' => false,
                    'message' => 'Coaching session has ended.',
                ], 403);
            }

            $question = Question::findOrFail($questionId);
            $examAttempt = $session->attempt;

            // Get user's answer for this question
            $examAnswer = ExamAnswer::where('attempt_id', $examAttempt->id)
                ->where('question_id', $questionId)
                ->firstOrFail();

            // Load conversation only for this question. Each question has its own dialogue thread.
            $dialogues = CoachingDialogue::where('session_id', $sessionId)
                ->where('question_id', $questionId)
                ->orderBy('step_number')
                ->orderBy('interaction_order')
                ->get();

            // Flow: step 1 = correct answer + explanation + resource (on Continue) → step 2 = explain your thought process → then Next question (no AI feedback).
            $currentStep = 1;
            if ($dialogues->isNotEmpty()) {
                $lastDialogue = $dialogues->last();
                if ($lastDialogue->step_number === 1 && $lastDialogue->ai_feedback) {
                    $currentStep = 2;
                } elseif ($lastDialogue->step_number === 2 && $lastDialogue->user_response) {
                    $currentStep = 3; // user submitted thought process → show Next question
                } elseif ($lastDialogue->step_number === 2) {
                    $currentStep = 2;
                } else {
                    $currentStep = $lastDialogue->step_number;
                }
            }

            // When opening a question, always show step 1 first (correct answer + explanation + resource). Use ?force_step=1 on first load.
            if ($request->query('force_step') == '1') {
                $currentStep = 1;
            }

            // Step 1: Correct answer + explanation + resource (source/reference)
            if ($currentStep === 1) {
                $existingDialogue = $dialogues->where('step_number', 1)->first();

                if ($existingDialogue && $existingDialogue->ai_feedback) {
                    $explanation = $existingDialogue->ai_feedback;
                } else {
                    // Always use AI to generate explanation (use the API key). Fallback to question explanation only on failure.
                    $storedExplanation = trim((string) ($question->explanation ?? ''));
                    try {
                        $explanation = $this->coachingService->generateStep3Explanation(
                            $question,
                            $examAnswer->selected_option ?? 'N/A',
                            null
                        );
                        if ($explanation === '' || strlen(trim($explanation)) < 50) {
                            $explanation = $storedExplanation ?: 'See the correct answer and guideline reference below.';
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Step 1 AI explanation failed, using question explanation', [
                            'question_id' => $questionId,
                            'session_id' => $sessionId,
                            'error' => $e->getMessage(),
                        ]);
                        $explanation = $storedExplanation ?: 'See the correct answer and guideline reference below.';
                    }

                    $interactionOrder = $dialogues->max('interaction_order') ?? 0;
                    CoachingDialogue::create([
                        'session_id' => $sessionId,
                        'question_id' => $questionId,
                        'step_number' => 1,
                        'ai_feedback' => $explanation,
                        'interaction_order' => $interactionOrder + 1,
                    ]);
                }

                $question = Question::findOrFail($questionId);

                // Only send excerpt if it's a real guideline excerpt, not question text repeated
                $stem = trim($question->stem ?? '');
                $excerpt = $question->guideline_excerpt;
                if ($excerpt && (str_contains($excerpt, 'Correct answer:') || trim($excerpt) === $stem || strlen(trim($excerpt)) < 30)) {
                    $excerpt = null;
                }

                return $this->noCacheJson([
                    'success' => true,
                    'data' => [
                        'question_id' => $questionId,
                        'step_number' => 1,
                        'topic' => $question->topic,
                        'correct_answer' => $question->correct_option,
                        'explanation' => $explanation,
                        'resource' => [
                            'source' => $question->guideline_source,
                            'reference' => $question->guideline_reference,
                            'url' => $question->guideline_url,
                            'excerpt' => $excerpt,
                        ],
                        'guideline_reference' => $question->guideline_reference,
                        'guideline_source' => $question->guideline_source,
                        'guideline_url' => $question->guideline_url,
                        'guideline_excerpt' => $excerpt,
                    ],
                ]);
            }

            // Step 2: Share your thought process
            if ($currentStep === 2) {
                $step1Dialogue = $dialogues->where('step_number', 1)->first();
                if (!$step1Dialogue || !$step1Dialogue->ai_feedback) {
                    return $this->noCacheJson([
                        'success' => false,
                        'message' => 'Please complete step 1 first.',
                        'data' => ['required_step' => 1],
                    ], 400);
                }

                $existingDialogue = $dialogues->where('step_number', 2)->first();

                if ($existingDialogue && $existingDialogue->ai_prompt) {
                    $aiPrompt = $existingDialogue->ai_prompt;
                } else {
                    $aiPrompt = 'Explain your own thought process — what did you understand?';
                    $interactionOrder = $dialogues->max('interaction_order') ?? 0;
                    CoachingDialogue::create([
                        'session_id' => $sessionId,
                        'question_id' => $questionId,
                        'step_number' => 2,
                        'ai_prompt' => $aiPrompt,
                        'interaction_order' => $interactionOrder + 1,
                    ]);
                }

                return $this->noCacheJson([
                    'success' => true,
                    'data' => [
                        'question_id' => $questionId,
                        'step_number' => 2,
                        'ai_prompt' => $aiPrompt,
                        'waiting_for_response' => true,
                    ],
                ]);
            }

            // Step 3: User submitted thought process — show Next question (no AI feedback)
            if ($currentStep === 3) {
                $step2Dialogue = $dialogues->where('step_number', 2)->first();
                if (!$step2Dialogue || !$step2Dialogue->user_response) {
                    return $this->noCacheJson([
                        'success' => false,
                        'message' => 'Please complete step 2 first.',
                        'data' => ['required_step' => 2],
                    ], 400);
                }

                return $this->noCacheJson([
                    'success' => true,
                    'data' => [
                        'question_id' => $questionId,
                        'step_number' => 3,
                        'is_question_complete' => true,
                        'message' => 'Proceed to the next question.',
                    ],
                ]);
            }

            return $this->noCacheJson([
                'success' => false,
                'message' => 'Invalid step number.',
            ], 400);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->noCacheJson([
                'success' => false,
                'message' => 'Coaching session or question not found.',
            ], 404);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to get current step. Please try again.', 500, [
                'session_id' => $sessionId,
                'question_id' => $questionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Submit user response and get AI feedback.
     */
    public function respond(CoachingRespondRequest $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Allow submit when in_progress or paused (same as getCurrentStep/getAllQuestions)
            if (!in_array($session->status, ['in_progress', 'paused'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Coaching session has ended.',
                ], 403);
            }

            $question = Question::findOrFail($request->question_id);

            // Load dialogue for this question only (session + question_id + step). Do not reuse another question's context.
            $dialogue = CoachingDialogue::where('session_id', $sessionId)
                ->where('question_id', $request->question_id)
                ->where('step_number', $request->step_number)
                ->whereNull('user_response')
                ->first();

            if (!$dialogue) {
                // Already submitted (e.g. double submit / retry) - return success so frontend can advance
                $existing = CoachingDialogue::where('session_id', $sessionId)
                    ->where('question_id', $request->question_id)
                    ->where('step_number', $request->step_number)
                    ->first();
                if ($existing) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Response already submitted.',
                        'data' => [
                            'question_id' => $request->question_id,
                            'step_number' => $request->step_number,
                            'user_response' => $existing->user_response,
                            'next_step' => $request->step_number === 2 ? 3 : null,
                        ],
                    ], 200);
                }
                return response()->json([
                    'success' => false,
                    'message' => 'Coaching session, question, or dialogue not found.',
                ], 404);
            }

            // Update dialogue with user response
            $dialogue->update([
                'user_response' => $request->response,
                'response_type' => $request->response_type ?? 'text',
            ]);

            // Step 2: Only save user's thought process — no AI feedback, next step is just "Next question"
            return response()->json([
                'success' => true,
                'message' => 'Response submitted successfully.',
                'data' => [
                    'question_id' => $request->question_id,
                    'step_number' => $request->step_number,
                    'user_response' => $request->response,
                    'next_step' => $request->step_number === 2 ? 3 : null,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coaching session, question, or dialogue not found.',
            ], 404);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to submit response. Please try again.', 500, [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Pause the coaching session.
     */
    public function pause(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Already paused or completed: treat as success so "End session" always works (idempotent)
            if ($session->status !== 'in_progress') {
                return response()->json([
                    'success' => true,
                    'message' => $session->status === 'paused'
                        ? 'Session is already paused.'
                        : 'Session has already ended.',
                    'data' => [
                        'session_id' => $session->id,
                        'status' => $session->status,
                        'paused_at' => $session->paused_at?->toIso8601String(),
                        'current_question_id' => $session->current_question_id,
                    ],
                ], 200);
            }

            // Update current question if provided
            if ($request->has('current_question_id')) {
                $session->current_question_id = $request->current_question_id;
            }

            $session->pause();

            return response()->json([
                'success' => true,
                'message' => 'Coaching session paused.',
                'data' => [
                    'session_id' => $session->id,
                    'status' => $session->status,
                    'paused_at' => $session->paused_at->toIso8601String(),
                    'current_question_id' => $session->current_question_id,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coaching session not found.',
            ], 404);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to pause session. Please try again.', 500, [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Update current question (when user navigates between questions).
     */
    public function updateCurrentQuestion(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->status !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only update current question for in-progress sessions.',
                ], 403);
            }

            $request->validate([
                'question_id' => 'required|integer|exists:questions,id',
            ]);

            $session->current_question_id = $request->question_id;
            $session->save();

            return response()->json([
                'success' => true,
                'message' => 'Current question updated.',
                'data' => [
                    'session_id' => $session->id,
                    'current_question_id' => $session->current_question_id,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coaching session not found.',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to update current question.', 500, [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Resume the coaching session.
     */
    public function resume(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->status !== 'paused') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only paused sessions can be resumed.',
                ], 403);
            }

            $session->resume();

            return response()->json([
                'success' => true,
                'message' => 'Coaching session resumed.',
                'data' => [
                    'session_id' => $session->id,
                    'status' => $session->status,
                    'paused_at' => $session->paused_at,
                    'current_question_id' => $session->current_question_id,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coaching session not found.',
            ], 404);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to resume session. Please try again.', 500, [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Complete the coaching session and generate summary.
     */
    public function complete(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::with(['attempt', 'dialogues.question'])
                ->where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->status === 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Coaching session is already completed.',
                ], 403);
            }

            DB::beginTransaction();

            try {
                // Update session status
                $session->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);

                // Get all dialogues for summary
                $dialogues = $session->dialogues;
                $questionsReviewed = $dialogues->pluck('question_id')->unique()->toArray();
                $topics = $dialogues->pluck('question.topic')->filter()->unique()->toArray();

                // Prepare interactions for summary
                $interactions = $dialogues->map(function ($d) {
                    return ($d->ai_prompt ?? '') . ' ' . ($d->user_response ?? '') . ' ' . ($d->ai_feedback ?? '');
                })->toArray();

                // Generate summary using AI
                $summaryContent = $this->coachingService->generateSummary(
                    $questionsReviewed,
                    $interactions,
                    $topics
                );

                // Extract learning points
                $learningPoints = $this->coachingService->extractLearningPoints($interactions);

                // Extract guidelines
                $questions = Question::whereIn('id', $questionsReviewed)->get();
                $guidelines = $this->coachingService->extractGuidelines($questions);

                // Create or update summary
                CoachingSummary::updateOrCreate(
                    ['session_id' => $sessionId],
                    [
                        'attempt_id' => $session->attempt_id,
                        'user_id' => $user->id,
                        'summary_content' => [
                            'text' => $summaryContent,
                        ],
                        'questions_reviewed' => $questionsReviewed,
                        'key_learning_points' => $learningPoints,
                        'guidelines_referenced' => $guidelines,
                    ]
                );

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Coaching session completed successfully.',
                    'data' => [
                        'session_id' => $session->id,
                        'status' => 'completed',
                        'completed_at' => $session->completed_at->toIso8601String(),
                    ],
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coaching session not found.',
            ], 404);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to complete session. Please try again.', 500, [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Get coaching session summary.
     */
    public function getSummary(Request $request, int $sessionId): JsonResponse
    {
        try {
            $user = $request->user();

            $session = CoachingSession::with('summary')
                ->where('id', $sessionId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($session->status !== 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Summary is only available for completed sessions.',
                ], 403);
            }

            if (!$session->summary) {
                return response()->json([
                    'success' => false,
                    'message' => 'Summary not yet generated. Please complete the session first.',
                ], 404);
            }

            $summary = $session->summary;

            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $session->id,
                    'summary_content' => $summary->summary_content,
                    'questions_reviewed' => $summary->questions_reviewed,
                    'key_learning_points' => $summary->key_learning_points,
                    'guidelines_referenced' => $summary->guidelines_referenced,
                    'created_at' => $summary->created_at->toIso8601String(),
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coaching session not found.',
            ], 404);
        } catch (\Throwable $e) {
            return $this->logAndRespond($e, 'Failed to fetch coaching summary.', 500, [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
            ]);
        }
    }
}
