<?php

namespace App\Services;

class QuestionTextParser
{
    public function parse(string $content, ?string $filename = null): array
    {
        $questions = [];

        // Detect topic from filename or content
        $detectedTopic = $this->detectTopic($content, $filename);

        // Extract CP and CC mappings first
        $cpMap = $this->extractCPCodes($content);
        $ccMap = $this->extractCCCodes($content);

        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        // Split content into sections - look for question patterns
        // Questions typically start with "A XX-year-old" or "What is" or numbered
        $lines = explode("\n", $content);

        $currentCP = null;
        $currentCC = null;
        $currentBlock = [];
        $questionNumber = 1;

        foreach ($lines as $lineNum => $line) {
            $trimmedLine = trim($line);

            // Skip empty lines at the start of blocks
            if (empty($trimmedLine) && empty($currentBlock)) {
                continue;
            }

            // Update CP/CC context
            if (preg_match('/^CP(\d+)\.\s*(.+)$/', $trimmedLine, $cpMatch)) {
                $currentCP = 'CP' . $cpMatch[1] . '. ' . trim($cpMatch[2]);
                // If we have a block, process it before starting new CP
                if (!empty($currentBlock)) {
                    $question = $this->processBlock(implode("\n", $currentBlock), $currentCP, $currentCC, $questionNumber, $detectedTopic);
                    if ($question) {
                        $questions[] = $question;
                        $questionNumber++;
                    }
                    $currentBlock = [];
                }
                continue;
            }

            if (preg_match('/^CC(\d+)\.\s*(.+)$/', $trimmedLine, $ccMatch)) {
                $currentCC = 'CC' . $ccMatch[1] . '. ' . trim($ccMatch[2]);
                // If we have a block, process it before starting new CC
                if (!empty($currentBlock)) {
                    $question = $this->processBlock(implode("\n", $currentBlock), $currentCP, $currentCC, $questionNumber, $detectedTopic);
                    if ($question) {
                        $questions[] = $question;
                        $questionNumber++;
                    }
                    $currentBlock = [];
                }
                continue;
            }

            // Detect start of new question
            // Questions start with patterns like:
            // - "A XX-year-old"
            // - "What is"
            // - Numbered "1. "
            // - Questions ending with "?"
            $isQuestionStart = false;
            if (preg_match('/^(A \d+[-–]year|What is|^\d+\.\s+[A-Z]|^[A-Z][^?]{15,}\?)/i', $trimmedLine)) {
                $isQuestionStart = true;
            }

            // If we detect a new question start and have accumulated a block
            if ($isQuestionStart && !empty($currentBlock)) {
                $blockText = implode("\n", $currentBlock);
                // Check if previous block has options (is a complete question)
                if (preg_match('/^\s*[A-E]\.\s+/m', $blockText)) {
                    $question = $this->processBlock($blockText, $currentCP, $currentCC, $questionNumber, $detectedTopic);
                    if ($question) {
                        $questions[] = $question;
                        $questionNumber++;
                    }
                }
                $currentBlock = [$line];
            } else {
                $currentBlock[] = $line;
            }
        }

        // Process the last block
        if (!empty($currentBlock)) {
            $blockText = implode("\n", $currentBlock);
            if (preg_match('/^\s*[A-E]\.\s+/m', $blockText)) {
                $question = $this->processBlock($blockText, $currentCP, $currentCC, $questionNumber, $detectedTopic);
                if ($question) {
                    $questions[] = $question;
                }
            }
        }

        return $questions;
    }

    private function processBlock(string $block, ?string $cp, ?string $cc, int $number, string $topic = 'Cardiology'): ?array
    {
        return $this->extractQuestion($block, $cp, $cc, $number, $topic);
    }

    private function detectTopic(string $content, ?string $filename = null): string
    {
        // Try to detect from filename first
        if ($filename) {
            $filename = strtolower($filename);
            if (strpos($filename, 'cardiology') !== false) {
                return 'Cardiology';
            }
            if (strpos($filename, 'procedural') !== false) {
                return 'Procedural';
            }
            if (strpos($filename, 'respiratory') !== false) {
                return 'Respiratory';
            }
            if (strpos($filename, 'trauma') !== false) {
                return 'Trauma';
            }
            if (strpos($filename, 'neurology') !== false) {
                return 'Neurology';
            }
        }

        // Try to detect from content (look for topic headers)
        if (preg_match('/^(Cardiology|Procedural|Respiratory|Trauma|Neurology|Paediatrics|Toxicology)/mi', $content, $match)) {
            return ucfirst(strtolower(trim($match[1])));
        }

        // Default to Cardiology if not detected
        return 'Cardiology';
    }

    private function extractCPCodes(string $content): array
    {
        $cpMap = [];
        preg_match_all('/CP(\d+)\.\s*([^\n]+)/', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $cpMap[$match[1]] = trim($match[2]);
        }
        return $cpMap;
    }

    private function extractCCCodes(string $content): array
    {
        $ccMap = [];
        preg_match_all('/CC(\d+)\.\s*([^\n]+)/', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $ccMap[$match[1]] = trim($match[2]);
        }
        return $ccMap;
    }

    private function extractQuestion(string $block, ?string $cp, ?string $cc, int $number, string $topic = 'Cardiology'): ?array
    {
        // Extract stem
        $stem = $this->extractStem($block);
        if (empty($stem) || strlen($stem) < 20) {
            return null;
        }

        // Extract options
        $options = $this->extractOptions($block);
        if (count($options) < 5) {
            return null;
        }

        // Extract correct answer
        $correctOption = $this->extractCorrectAnswer($block);
        if (!$correctOption) {
            return null;
        }

        // Extract explanation
        $explanation = $this->extractExplanation($block);
        if (empty($explanation)) {
            $explanation = $this->extractExplanationFallback($block);
        }

        // Extract references
        $references = $this->extractReferences($block);

        // Extract guideline info
        $guidelineInfo = $this->extractGuidelineInfo($block);

        return [
            'question_number' => $number,
            'stem' => $stem,
            'option_a' => $options['A'] ?? '',
            'option_b' => $options['B'] ?? '',
            'option_c' => $options['C'] ?? '',
            'option_d' => $options['D'] ?? '',
            'option_e' => $options['E'] ?? '',
            'correct_option' => $correctOption,
            'explanation' => $explanation ?? 'Explanation not found in source.',
            'clinical_presentation' => $cp,
            'condition_code' => $cc,
            'topic' => $topic,
            'subtopic' => $this->getSubtopicFromCC($cc),
            'guideline_reference' => $guidelineInfo['reference'] ?? null,
            'guideline_excerpt' => $guidelineInfo['excerpt'] ?? null,
            'guideline_source' => $guidelineInfo['source'] ?? null,
            'guideline_url' => $guidelineInfo['url'] ?? null,
            'references' => !empty($references) ? $references : null,
            'difficulty' => 'Medium',
            'is_active' => true,
            'has_image' => false,
        ];
    }

    private function extractStem(string $block): ?string
    {
        // Remove CP/CC lines from start
        $block = preg_replace('/^(CP\d+|CC\d+)[^\n]*\n?/m', '', $block);
        $block = trim($block);

        // Find everything before the first option (A. or Option A)
        if (preg_match('/^(.*?)(?=^\s*A\.\s|^Option A|^\s*[A-E]\.\s+[A-Z])/sm', $block, $match)) {
            $stem = trim($match[1]);

            // Remove leading numbers
            $stem = preg_replace('/^\d+\.\s*/', '', $stem);

            // Remove any remaining CP/CC references
            $stem = preg_replace('/^(CP\d+|CC\d+)[^\n]*\n?/m', '', $stem);

            // Extract the question part (should end with ?)
            if (preg_match('/(.*?\?)/s', $stem, $qMatch)) {
                $stem = trim($qMatch[1]);
            }

            $stem = trim($stem);

            // Must have at least 20 characters and contain a question mark or question words
            if (strlen($stem) >= 20 && (strpos($stem, '?') !== false || preg_match('/(what|which|how|why|when|where|who)/i', $stem))) {
                return $stem;
            }
        }

        return null;
    }

    private function extractOptions(string $block): array
    {
        $options = [];

        // Pattern: A. text (most common format)
        // Match from start of line, continue until next option or explanation starts
        if (preg_match_all('/^\s*([A-E])\.\s+([^\n]+(?:\n(?!^\s*[A-E]\.|Rationale|Explanation|The\s+most|Correct|Answer|Why|Differentiating|References)[^\n]+)*)/m', $block, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $optionText = trim($match[2]);
                // Remove trailing period if it's just punctuation
                $optionText = preg_replace('/\.$/', '', $optionText);
                // Clean up whitespace
                $optionText = preg_replace('/\s+/', ' ', $optionText);
                if (!empty($optionText) && strlen($optionText) > 3) {
                    $options[$match[1]] = $optionText;
                }
            }
        }

        // Pattern 2: Option A: format
        if (count($options) < 5) {
            foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
                if (isset($options[$letter])) {
                    continue;
                }
                if (preg_match('/Option\s+' . $letter . '[:\s]+([^\n]+(?:\n(?!Option\s+[A-E]|^\s*[A-E]\.|Rationale|Explanation)[^\n]+)*)/i', $block, $match)) {
                    $options[$letter] = trim($match[1]);
                }
            }
        }

        return $options;
    }

    private function extractCorrectAnswer(string $block): ?string
    {
        $patterns = [
            '/The\s+(?:most\s+)?(?:appropriate|correct|best|next\s+best\s+step)\s+(?:answer|step|treatment|initial\s+treatment|next\s+step)[:\s]+([A-E])/i',
            '/The\s+next\s+best\s+step\s+is\s+([A-E])/i',
            '/correct\s+answer[:\s]+([A-E])/i',
            '/answer[:\s]+([A-E])/i',
            '/is\s+([A-E])\./i',
            '/Correct answer:?\s*([A-E])/i',
            '/The\s+answer\s+is\s+([A-E])/i',
            '/The\s+correct\s+answer\s+is\s+([A-E])/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $block, $match)) {
                $answer = strtoupper(trim($match[1]));
                if (in_array($answer, ['A', 'B', 'C', 'D', 'E'])) {
                    return $answer;
                }
            }
        }

        return null;
    }

    private function extractExplanation(string $block): ?string
    {
        $patterns = [
            '/Rationale[:\s]+(.*?)(?=References|Why|Differentiating|Rationale on|$)/is',
            '/Rationale for[:\s]+(.*?)(?=References|Why|Differentiating|Rationale on|$)/is',
            '/Rationale for Management[:\s]+(.*?)(?=References|Why|Differentiating|Rationale on|$)/is',
            '/Explanation[:\s]+(.*?)(?=References|Why|Differentiating|Rationale on|$)/is',
            '/This\s+(?:is|patient|scenario|diagnosis)(.*?)(?=References|Why|Differentiating|Rationale on|$)/is',
            '/The\s+(?:most\s+)?(?:appropriate|correct|best|likely)(.*?)(?=References|Why|Differentiating|Rationale on|$)/is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $block, $match)) {
                $explanation = trim($match[1]);
                $explanation = preg_replace('/^Why\s+[A-E]\s+is\s+the\s+correct\s+answer[:\s]*/i', '', $explanation);
                $explanation = preg_replace('/[ \t]+/', ' ', $explanation);
                $explanation = preg_replace('/\n{3,}/', "\n\n", $explanation);
                if (strlen($explanation) > 50) {
                    return trim($explanation);
                }
            }
        }

        return null;
    }

    private function extractExplanationFallback(string $block): ?string
    {
        // Try to get text after options but before references
        if (preg_match('/(?:[A-E]\.\s+[^\n]+(?:\n(?!^\s*[A-E]\.)[^\n]+)*){5}(.*?)(?=References|$)/is', $block, $match)) {
            $explanation = trim($match[1]);
            // Remove answer indicators
            $explanation = preg_replace('/^(The\s+(?:most\s+)?(?:appropriate|correct|best)\s+(?:answer|step|treatment)[:\s]+[A-E]\.?\s*)/i', '', $explanation);
            if (strlen($explanation) > 50) {
                return trim($explanation);
            }
        }

        return null;
    }

    private function extractReferences(string $block): array
    {
        $references = [];

        // Pattern: Title followed by URL (can be on same or next line)
        if (preg_match_all('/(?:●|•|References?|Reference)[:\s]*\n?\s*([^\n]+?)\s+(https?:\/\/[^\s\)]+)/i', $block, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $title = trim($match[1]);
                $url = trim($match[2]);

                // Clean up title (remove extra formatting)
                $title = preg_replace('/^[●•]\s*/', '', $title);

                if (!empty($title) && !empty($url)) {
                    $references[] = [
                        'title' => $title,
                        'url' => $url,
                    ];
                }
            }
        }

        // Also try to match URLs that appear after text (even without explicit "Reference" label)
        if (empty($references)) {
            // Look for URLs and try to find preceding text as title
            if (preg_match_all('/([A-Z][^:]+?)\s+(https?:\/\/[^\s\)]+)/i', $block, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $title = trim($match[1]);
                    $url = trim($match[2]);

                    // Skip if it looks like part of an option
                    if (preg_match('/^[A-E]\./i', $title)) {
                        continue;
                    }

                    if (strlen($title) > 5 && strlen($title) < 200) {
                        $references[] = [
                            'title' => $title,
                            'url' => $url,
                        ];
                    }
                }
            }
        }

        return $references;
    }

    private function extractGuidelineInfo(string $block): array
    {
        $info = [];

        // Extract guideline reference (NICE, ESC, etc.)
        if (preg_match('/((?:NICE|ESC|AHA|ACC)[^:]*:[^\n]+)/i', $block, $match)) {
            $info['reference'] = trim($match[1]);

            // Extract source name
            if (preg_match('/(NICE|ESC|AHA|ACC)[^:]*/i', $match[1], $sourceMatch)) {
                $info['source'] = trim($sourceMatch[1]);
            }
        }

        // Extract URL (first URL found, or URL near guideline text)
        if (preg_match('/(https?:\/\/[^\s\)]+)/', $block, $urlMatch)) {
            $info['url'] = trim($urlMatch[1]);
        }

        return $info;
    }

    private function getSubtopicFromCC(?string $cc): ?string
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
