<?php

namespace App\Console\Commands;

use App\Models\Question;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportCardiologyQuestions extends Command
{
    protected $signature = 'questions:import-cardiology {file}';
    protected $description = 'Import cardiology questions from structured text file';

    public function handle()
    {
        $filePath = $this->argument('file');

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $content = file_get_contents($filePath);
        $questions = $this->parseQuestions($content);

        $this->info("Found " . count($questions) . " questions to import.");

        $bar = $this->output->createProgressBar(count($questions));
        $bar->start();

        $imported = 0;
        $skipped = 0;

        foreach ($questions as $questionData) {
            try {
                // Check if question already exists (by stem)
                $exists = Question::where('stem', $questionData['stem'])->exists();

                if ($exists) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                Question::create($questionData);
                $imported++;
                $bar->advance();
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Error importing question: " . $e->getMessage());
                $this->error("Question: " . substr($questionData['stem'], 0, 100) . "...");
                $skipped++;
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info("Import completed!");
        $this->info("Imported: {$imported}");
        $this->info("Skipped: {$skipped}");

        return 0;
    }

    private function parseQuestions(string $content): array
    {
        $questions = [];

        // Split by question patterns - look for numbered questions or question stems
        // Pattern: Question text followed by options A-E

        // First, extract clinical presentations and condition codes
        preg_match_all('/CP(\d+)\.\s*([^\n]+)/', $content, $cpMatches, PREG_SET_ORDER);
        preg_match_all('/CC(\d+)\.\s*([^\n]+)/', $content, $ccMatches, PREG_SET_ORDER);

        $cpMap = [];
        $ccMap = [];

        foreach ($cpMatches as $match) {
            $cpMap[$match[1]] = trim($match[2]);
        }

        foreach ($ccMatches as $match) {
            $ccMap[$match[1]] = trim($match[2]);
        }

        // Split content into sections by clinical presentations
        $sections = preg_split('/(?=CP\d+\.|CC\d+\.)/', $content);

        $currentCP = null;
        $currentCC = null;
        $questionNumber = 1;

        foreach ($sections as $section) {
            // Extract CP and CC from section
            if (preg_match('/CP(\d+)\./', $section, $cpMatch)) {
                $currentCP = 'CP' . $cpMatch[1] . '. ' . ($cpMap[$cpMatch[1]] ?? '');
            }

            if (preg_match('/CC(\d+)\./', $section, $ccMatch)) {
                $currentCC = 'CC' . $ccMatch[1] . '. ' . ($ccMap[$ccMatch[1]] ?? '');
            }

            // Extract questions from this section
            $sectionQuestions = $this->extractQuestionsFromSection($section, $currentCP, $currentCC, $questionNumber);
            $questions = array_merge($questions, $sectionQuestions);
            $questionNumber += count($sectionQuestions);
        }

        return $questions;
    }

    private function extractQuestionsFromSection(string $section, ?string $cp, ?string $cc, int $startNumber): array
    {
        $questions = [];

        // Pattern to match question blocks
        // Look for question stem (usually ends with "?" or "What is...")
        // Followed by options A-E
        // Followed by explanation

        // Split by potential question markers
        $questionBlocks = preg_split('/(?=\d+\.\s+[A-Z]|What is|A \d+[-–]year|^\s*[A-Z][^?]*\?)/m', $section);

        foreach ($questionBlocks as $block) {
            $block = trim($block);
            if (empty($block) || strlen($block) < 50) {
                continue;
            }

            // Extract question stem (everything before options)
            if (!preg_match('/^(.*?)(?=A\.|Option A|^\s*A\.)/s', $block, $stemMatch)) {
                continue;
            }

            $stem = trim($stemMatch[1]);
            $stem = preg_replace('/^\d+\.\s*/', '', $stem); // Remove leading number
            $stem = trim($stem);

            if (empty($stem) || strlen($stem) < 20) {
                continue;
            }

            // Extract options A-E
            $options = [];
            $correctOption = null;

            // Pattern for options: A. text, B. text, etc.
            if (preg_match_all('/([A-E])\.\s*([^\n]+(?:\n(?!\s*[A-E]\.)[^\n]+)*)/', $block, $optionMatches, PREG_SET_ORDER)) {
                foreach ($optionMatches as $match) {
                    $optionLetter = $match[1];
                    $optionText = trim($match[2]);
                    $options[$optionLetter] = $optionText;
                }
            }

            // If we didn't find options with A. pattern, try "Option A" pattern
            if (empty($options)) {
                foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
                    if (preg_match('/Option\s+' . $letter . '[:\s]+([^\n]+(?:\n(?!Option\s+[A-E])[^\n]+)*)/i', $block, $optMatch)) {
                        $options[$letter] = trim($optMatch[1]);
                    }
                }
            }

            // Extract correct answer
            if (preg_match('/correct\s+answer[:\s]+([A-E])/i', $block, $correctMatch)) {
                $correctOption = strtoupper($correctMatch[1]);
            } elseif (preg_match('/answer[:\s]+([A-E])/i', $block, $answerMatch)) {
                $correctOption = strtoupper($answerMatch[1]);
            } elseif (preg_match('/The\s+(?:most\s+)?(?:appropriate|correct|best)\s+(?:answer|step|treatment)[:\s]+([A-E])/i', $block, $bestMatch)) {
                $correctOption = strtoupper($bestMatch[1]);
            }

            // Extract explanation
            $explanation = '';
            if (preg_match('/Rationale[:\s]+(.*?)(?=References|$)/is', $block, $explMatch)) {
                $explanation = trim($explMatch[1]);
            } elseif (preg_match('/Explanation[:\s]+(.*?)(?=References|$)/is', $block, $explMatch)) {
                $explanation = trim($explMatch[1]);
            } elseif (preg_match('/This\s+(?:is|patient)(.*?)(?=References|$)/is', $block, $explMatch)) {
                $explanation = trim($explMatch[1]);
            }

            // Extract references
            $references = [];
            if (preg_match_all('/(?:●|•|References?)[:\s]*\n?\s*([^\n]+?)\s+(https?:\/\/[^\s\)]+)/i', $block, $refMatches, PREG_SET_ORDER)) {
                foreach ($refMatches as $refMatch) {
                    $references[] = [
                        'title' => trim($refMatch[1]),
                        'url' => trim($refMatch[2]),
                    ];
                }
            }

            // Extract guideline references
            $guidelineReference = null;
            $guidelineUrl = null;
            if (preg_match('/(NICE|ESC|AHA|ACC)[^:]*:([^\n]+)/i', $block, $guidelineMatch)) {
                $guidelineReference = trim($guidelineMatch[0]);
                if (preg_match('/(https?:\/\/[^\s\)]+)/', $block, $urlMatch)) {
                    $guidelineUrl = trim($urlMatch[1]);
                }
            }

            // Only create question if we have minimum required fields
            if (!empty($stem) && count($options) >= 5 && !empty($correctOption) && !empty($explanation)) {
                $questions[] = [
                    'question_number' => $startNumber++,
                    'stem' => $stem,
                    'option_a' => $options['A'] ?? '',
                    'option_b' => $options['B'] ?? '',
                    'option_c' => $options['C'] ?? '',
                    'option_d' => $options['D'] ?? '',
                    'option_e' => $options['E'] ?? '',
                    'correct_option' => $correctOption,
                    'explanation' => $explanation,
                    'clinical_presentation' => $cp,
                    'condition_code' => $cc,
                    'topic' => 'Cardiology',
                    'subtopic' => $this->extractSubtopic($cc),
                    'guideline_reference' => $guidelineReference,
                    'guideline_url' => $guidelineUrl,
                    'references' => !empty($references) ? $references : null,
                    'difficulty' => 'Medium',
                    'is_active' => true,
                    'has_image' => false,
                ];
            }
        }

        return $questions;
    }

    private function extractSubtopic(?string $cc): ?string
    {
        if (!$cc) {
            return null;
        }

        $subtopicMap = [
            'CC1' => 'Acute Coronary Syndromes',
            'CC2' => 'Myocardial Infarction',
            'CC3' => 'Arrhythmias',
            'CC4' => 'Cardiac Failure',
            'CC5' => 'Cardiac Tamponade',
            'CC6' => 'Congenital Heart Disease',
            'CC7' => 'Diseases of the Arteries',
            'CC8' => 'Diseases of Myocardium',
            'CC9' => 'Hypertensive Emergencies',
            'CC10' => 'Pacemaker Function & Failure',
            'CC11' => 'Pericardial Disease',
            'CC12' => 'Sudden Cardiac Death',
            'CC13' => 'Valvular Heart Disease',
        ];

        if (preg_match('/CC(\d+)/', $cc, $match)) {
            return $subtopicMap['CC' . $match[1]] ?? null;
        }

        return null;
    }
}
