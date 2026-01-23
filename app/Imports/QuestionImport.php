<?php

namespace App\Imports;

use App\Models\Question;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterImport;

class QuestionImport implements ToModel, WithHeadingRow, WithValidation, SkipsEmptyRows, WithBatchInserts, WithChunkReading, WithEvents
{
    protected $importedCount = 0;
    protected $skippedCount = 0;
    protected $errorCount = 0;
    protected $errors = [];
    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        try {
            // Check if question already exists to avoid duplicates
            $stem = $row['stem'] ?? $row['question'] ?? $row['question_stem'] ?? '';
            if (empty($stem)) {
                $this->errorCount++;
                $this->errors[] = "Row skipped: Missing question stem";
                return null;
            }

            // Check for duplicate - but don't skip, let it be handled by unique constraint or update logic
            // We'll let the database handle duplicates or update existing ones

            // Parse references if provided (can be JSON string or pipe-separated)
            $references = null;
            if (!empty($row['references'])) {
                $refData = $row['references'];
                // Try to decode as JSON first
                $decoded = json_decode($refData, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decode   d)) {
                    $references = $decoded;
                } else {
                    // If not JSON, try pipe-separated format: "Title1|URL1||Title2|URL2"
                    $refs = explode('||', $refData);
                    $references = [];
                    foreach ($refs as $ref) {
                        $parts = explode('|', trim($ref));
                        if (count($parts) >= 2) {
                            $references[] = [
                                'title' => trim($parts[0]),
                                'url' => trim($parts[1]),
                            ];
                        } elseif (count($parts) === 1 && !empty(trim($parts[0]))) {
                            $references[] = [
                                'title' => trim($parts[0]),
                                'url' => null,
                            ];
                        }
                    }
                    if (empty($references)) {
                        $references = null;
                    }
                }
            }

            // Use updateOrCreate to handle duplicates - update if exists, create if not
            $question = Question::updateOrCreate(
                ['stem' => $stem], // Match by stem
                [
                    'question_number' => $row['question_number'] ?? null,
                    'scenario' => $row['scenario'] ?? $row['vignette'] ?? $row['clinical_scenario'] ?? null,
                    'option_a' => $row['option_a'] ?? $row['option_a_text'] ?? '',
                    'option_b' => $row['option_b'] ?? $row['option_b_text'] ?? '',
                    'option_c' => $row['option_c'] ?? $row['option_c_text'] ?? '',
                    'option_d' => $row['option_d'] ?? $row['option_d_text'] ?? '',
                    'option_e' => $row['option_e'] ?? $row['option_e_text'] ?? '',
                    'correct_option' => strtoupper($row['correct_option'] ?? $row['correct_answer'] ?? $row['answer'] ?? 'A'),
                    'explanation' => $row['explanation'] ?? $row['answer_explanation'] ?? '',
                    'clinical_presentation' => $row['clinical_presentation'] ?? $row['clinical_presentation_code'] ?? null,
                    'condition_code' => $row['condition_code'] ?? $row['condition'] ?? null,
                    'topic' => $row['topic'] ?? $row['subject'] ?? 'General',
                    'subtopic' => $row['subtopic'] ?? $row['sub_topic'] ?? null,
                    'guideline_reference' => $row['guideline_reference'] ?? $row['guideline'] ?? null,
                    'guideline_excerpt' => $row['guideline_excerpt'] ?? null,
                    'guideline_source' => $row['guideline_source'] ?? null,
                    'guideline_url' => $row['guideline_url'] ?? null,
                    'references' => $references,
                    'image_url' => $row['image_url'] ?? $row['image'] ?? null,
                    'has_image' => !empty($row['image_url']) || !empty($row['image']) || (isset($row['has_image']) && (strtolower($row['has_image']) === 'yes' || $row['has_image'] === '1' || $row['has_image'] === true)),
                    'difficulty' => $this->normalizeDifficulty($row['difficulty'] ?? $row['level'] ?? 'Medium'),
                    'is_active' => !isset($row['is_active']) || strtolower($row['is_active']) === 'yes' || $row['is_active'] === '1' || $row['is_active'] === true || $row['is_active'] === 'true',
                ]
            );

            if ($question->wasRecentlyCreated) {
                $this->importedCount++;
            } else {
                $this->skippedCount++;
            }

            return $question;
        } catch (\Exception $e) {
            $this->errorCount++;
            $this->errors[] = "Error importing row: " . $e->getMessage();
            Log::error('Question import error', [
                'row' => $row,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Normalize difficulty value
     */
    private function normalizeDifficulty($difficulty): string
    {
        $difficulty = strtolower(trim($difficulty));

        if (in_array($difficulty, ['easy', 'e', '1'])) {
            return 'Easy';
        } elseif (in_array($difficulty, ['hard', 'h', '3', 'difficult'])) {
            return 'Hard';
        }

        return 'Medium';
    }

    /**
     * Validation rules
     */
    public function rules(): array
    {
        return [
            'stem' => 'required|string',
            'option_a' => 'required|string',
            'option_b' => 'required|string',
            'option_c' => 'required|string',
            'option_d' => 'required|string',
            'option_e' => 'required|string',
            'correct_option' => 'required|in:A,B,C,D,E,a,b,c,d,e',
            'explanation' => 'required|string',
            'topic' => 'required|string',
            'difficulty' => 'nullable|in:Easy,Medium,Hard,easy,medium,hard,E,M,H',
        ];
    }

    /**
     * Batch size for inserts
     */
    public function batchSize(): int
    {
        return 100;
    }

    /**
     * Chunk size for reading
     */
    public function chunkSize(): int
    {
        return 100;
    }

    /**
     * Register events
     */
    public function registerEvents(): array
    {
        return [
            AfterImport::class => function (AfterImport $event) {
                Log::info('Question import completed', [
                    'imported' => $this->importedCount,
                    'skipped' => $this->skippedCount,
                    'errors' => $this->errorCount,
                ]);
            },
        ];
    }

    /**
     * Get import statistics
     */
    public function getStats(): array
    {
        return [
            'imported' => $this->importedCount,
            'skipped' => $this->skippedCount,
            'errors' => $this->errorCount,
            'error_messages' => $this->errors,
        ];
    }
}
