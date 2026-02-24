<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Question;
use App\Models\ExamAnswer;

class OpenAICoachingService
{
    private string $apiKey;
    private string $model;
    private int $maxTokens;
    private float $temperature;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
        $this->model = config('services.openai.model', 'gpt-4');
        $this->maxTokens = config('services.openai.max_tokens', 1000);
        $this->temperature = config('services.openai.temperature', 0.4);
    }

    /**
     * Get the system prompt for the AI coach.
     */
    private function getSystemPrompt(): string
    {
        return "You are an expert MRCEM (Membership of the Royal College of Emergency Medicine) exam coach.
Your role is to help medical professionals prepare for their emergency medicine exams through Socratic questioning and guided learning.

CRITICAL - Accuracy and scope:
- Answer ONLY using the provided scenario, question stem, options, and any guideline/excerpt given to you. Do not add, invent, or assume information that is not explicitly provided.
- Do not use external knowledge beyond what is supplied in the prompt. If something is not in the provided material, do not state it as fact.
- When referencing guidelines, use only the guideline reference, excerpt, or URL that was provided.

Key principles:
- Use evidence-based medicine and clinical guidelines only when they are provided in the question/guideline data
- Ask probing questions to understand the learner's thinking
- Provide constructive feedback without being condescending
- Focus on clinical reasoning and decision-making processes
- Keep responses concise but educational (2-3 paragraphs maximum)
- Use medical terminology appropriately
- Encourage critical thinking";
    }

    /**
     * Generate prompt for Step 2: Ask about user's thinking.
     */
    public function generateStep2Prompt(Question $question, string $userSelectedOption): string
    {
        $prompt = "Use ONLY the information below. Do not add or invent any facts.

Question: {$question->stem}
Scenario: " . ($question->scenario ?? 'N/A') . "

Options:
A. {$question->option_a}
B. {$question->option_b}
C. {$question->option_c}
D. {$question->option_d}
E. {$question->option_e}

The user selected option {$userSelectedOption}.

Generate a friendly, probing question asking the user to explain their thinking process when they selected option {$userSelectedOption}. Base your question only on the scenario and options above. Keep it conversational and supportive (1-2 sentences).";

        return $this->callOpenAI($prompt);
    }

    /**
     * Generate explanation for Step 3: Reveal correct answer with guideline reference.
     */
    public function generateStep3Explanation(Question $question, string $userSelectedOption, ?string $userReasoning = null): string
    {
        $guidelineInfo = '';
        if ($question->guideline_reference) {
            $guidelineInfo = "\n\nGuideline Reference: {$question->guideline_reference}";
            if ($question->guideline_url) {
                $guidelineInfo .= "\nGuideline URL: {$question->guideline_url}";
            }
            if ($question->guideline_excerpt) {
                $guidelineInfo .= "\nGuideline Excerpt: {$question->guideline_excerpt}";
            }
        }

        $userReasoningText = $userReasoning ? "\n\nUser's reasoning: {$userReasoning}" : '';

        $prompt = "Answer ONLY using the information provided below. Do not add or invent any facts.

Question: {$question->stem}
Scenario: " . ($question->scenario ?? 'N/A') . "

Options:
A. {$question->option_a}
B. {$question->option_b}
C. {$question->option_c}
D. {$question->option_d}
E. {$question->option_e}

User selected: Option {$userSelectedOption}
Correct answer: Option {$question->correct_option}{$userReasoningText}

{$guidelineInfo}

Explain why option {$question->correct_option} is correct using only the scenario, options, and guideline information above. If the user selected incorrectly, gently explain why their choice was not optimal based only on this material. Do not introduce information from outside this prompt. Keep it educational and supportive (2-3 paragraphs).";

        return $this->callOpenAI($prompt);
    }

    /**
     * Generate prompt for Step 4: Ask user to explain correct reasoning.
     * Short static message (no AI call).
     */
    public function generateStep4Prompt(Question $question): string
    {
        return 'Please share your thought process in your own words.';
    }

    /**
     * Generate feedback for Step 4: Validate user's explanation of correct reasoning.
     */
    public function generateStep4Feedback(Question $question, string $userExplanation): string
    {
        $prompt = "Answer ONLY using the information provided below. Do not add or invent any facts.

Question: {$question->stem}
Scenario: " . ($question->scenario ?? 'N/A') . "
Correct answer: Option {$question->correct_option}
Correct explanation (use this as the reference): {$question->explanation}

User's explanation: {$userExplanation}

Provide constructive feedback on the user's explanation based only on the correct explanation and scenario above. Acknowledge what they got right, gently correct any misconceptions using only this material, and reinforce key learning points. Do not introduce external or invented information. Keep it encouraging (2-3 paragraphs).";

        return $this->callOpenAI($prompt);
    }

    /**
     * Generate follow-up question for Step 5: Adjacent concepts.
     * Only pass previousInteractions from the SAME question to avoid cross-question context.
     */
    public function generateStep5FollowUp(Question $question, array $previousInteractions = []): string
    {
        $context = '';
        if (!empty($previousInteractions)) {
            $context = "\n\nPrevious interactions for this question only:\n" . implode("\n", array_slice($previousInteractions, -3));
        }

        $prompt = "Answer only using the question context below. Do not add or invent clinical details.

Question: {$question->stem}
Scenario: " . ($question->scenario ?? 'N/A') . "
Topic: {$question->topic}
Subtopic: {$question->subtopic}{$context}

Generate 1-2 probing questions about adjacent or related concepts that would deepen the learner's understanding, based only on this question's topic and scenario. Keep each question concise (1 sentence each).";

        return $this->callOpenAI($prompt);
    }

    /**
     * Generate coaching summary for the entire session.
     */
    public function generateSummary(array $questionsReviewed, array $interactions, array $topics): string
    {
        $questionsSummary = "Questions reviewed: " . count($questionsReviewed);
        $topicsSummary = "Topics covered: " . implode(', ', array_unique($topics));

        $prompt = "Generate a comprehensive coaching session summary for an MRCEM exam preparation session.

{$questionsSummary}
{$topicsSummary}

Key interactions and learning points from the session:
" . implode("\n", array_slice($interactions, 0, 10)) . "

Create a structured summary that includes:
1. Overall performance insights
2. Key learning points (3-5 bullet points)
3. Areas for improvement
4. Recommended next steps for study

Keep it professional, encouraging, and actionable (4-5 paragraphs).";

        return $this->callOpenAI($prompt);
    }

    /**
     * Make API call to OpenAI.
     */
    private function callOpenAI(string $userPrompt): string
    {
        if (empty($this->apiKey)) {
            Log::error('OpenAI API key is not configured');
            throw new \Exception('OpenAI API key is not configured. Please set OPENAI_API_KEY in your .env file.');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->getSystemPrompt(),
                    ],
                    [
                        'role' => 'user',
                        'content' => $userPrompt,
                    ],
                ],
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
            ]);

            if ($response->failed()) {
                Log::error('OpenAI API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('OpenAI API request failed: ' . $response->body());
            }

            $data = $response->json();

            if (!isset($data['choices'][0]['message']['content'])) {
                Log::error('OpenAI API response missing content', ['response' => $data]);
                throw new \Exception('Invalid response from OpenAI API');
            }

            return trim($data['choices'][0]['message']['content']);
        } catch (\Exception $e) {
            Log::error('OpenAI API error', [
                'message' => $e->getMessage(),
                'prompt' => substr($userPrompt, 0, 200),
            ]);
            throw $e;
        }
    }

    /**
     * Extract key learning points from interactions.
     */
    public function extractLearningPoints(array $interactions): array
    {
        if (empty($interactions)) {
            return [];
        }

        $prompt = "From these coaching interactions, extract 3-5 key learning points as concise bullet points:\n\n"
            . implode("\n\n", array_slice($interactions, 0, 5));

        $response = $this->callOpenAI($prompt);

        // Parse bullet points from response
        $points = [];
        $lines = explode("\n", $response);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^[-•*]\s*(.+)$/', $line, $match) || preg_match('/^\d+\.\s*(.+)$/', $line, $match)) {
                $points[] = trim($match[1]);
            }
        }

        return array_slice($points, 0, 5);
    }

    /**
     * Extract guidelines referenced in the session.
     */
    public function extractGuidelines($questions): array
    {
        $guidelines = [];

        foreach ($questions as $question) {
            // Handle both objects and arrays
            $guidelineRef = is_array($question) ? ($question['guideline_reference'] ?? null) : $question->guideline_reference;

            if ($guidelineRef) {
                $guidelines[] = [
                    'reference' => $guidelineRef,
                    'source' => is_array($question) ? ($question['guideline_source'] ?? null) : $question->guideline_source,
                    'url' => is_array($question) ? ($question['guideline_url'] ?? null) : $question->guideline_url,
                ];
            }
        }

        return array_unique($guidelines, SORT_REGULAR);
    }
}
