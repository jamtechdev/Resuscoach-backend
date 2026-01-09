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

            // Detect start of new question block (only for numbered questions or clear separators)
            // Don't treat question words like "What is" as new question starts - they're part of the scenario
            $isQuestionStart = false;
            // Only treat as new question if:
            // - Numbered question (1. or Question 1:)
            // - Clinical scenario starter (A XX-year-old, You are)
            // - But NOT just question words (What is, Which, etc.) - those are part of the question text
            if (preg_match('/^(A \d+[-–](year|month|week|day)|You are|^\d+\.\s+[A-Z]|^Question\s+\d+[:\s])/i', $trimmedLine)) {
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
                // Always add to current block - question words are part of the question text, not separators
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
        // Extract scenario and stem (question text)
        $scenarioAndStem = $this->extractStem($block);
        if (empty($scenarioAndStem) || strlen($scenarioAndStem) < 20) {
            return null;
        }

        // Separate scenario from question
        $scenario = $this->extractScenario($scenarioAndStem);
        $stem = $this->extractQuestionText($scenarioAndStem);

        // If we couldn't separate them, put everything in stem (backward compatibility)
        if (empty($stem)) {
            $stem = $scenarioAndStem;
            $scenario = null;
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
            'scenario' => $scenario,
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
        // This includes the full clinical scenario/vignette AND the question
        // Use a more flexible pattern that captures everything up to the first option line
        // Match: "A. ", "A.", "Option A:", etc. at start of line
        if (preg_match('/^(.*?)(?=^\s*[A-E][\.:]\s|^Option\s+[A-E][:\s]|^\s*[A-E]\.\s+[A-Z])/sm', $block, $match)) {
            $stem = trim($match[1]);

            // Remove leading question numbers (like "1. " or "Question 1:" or "a. ")
            $stem = preg_replace('/^(Question\s+)?[a-z]?[0-9]+[\.:]\s*/i', '', $stem);

            // Remove any remaining CP/CC references
            $stem = preg_replace('/^(CP\d+|CC\d+)[^\n]*\n?/m', '', $stem);

            // Remove "Correct Answer" lines that might appear before options
            $stem = preg_replace('/Correct\s+answer[:\s]+[A-E]\.?\s*[^\n]*\n?/i', '', $stem);
            $stem = preg_replace('/Correct\s+Answer[:\s]+[A-E]\.?\s*[^\n]*\n?/i', '', $stem);

            // Remove "Explanation:" or "Rationale" sections that might appear before options
            $stem = preg_replace('/\n\s*(Explanation|Rationale|Rationale\s+for|Rationale\s+Against)[:\s].*$/is', '', $stem);

            // Remove "Reference:" sections that might appear before options
            $stem = preg_replace('/\n\s*Reference[s]?[:\s].*$/is', '', $stem);

            // Clean up extra whitespace but preserve line breaks for readability
            // Only normalize spaces/tabs within lines, not across line breaks
            $lines = explode("\n", $stem);
            $lines = array_map(function ($line) {
                return preg_replace('/[ \t]+/', ' ', trim($line));
            }, $lines);
            $stem = implode("\n", array_filter($lines)); // Remove empty lines
            $stem = preg_replace('/\n{3,}/', "\n\n", $stem); // Max 2 line breaks
            $stem = trim($stem);

            // Must have at least 20 characters
            // The clinical scenario before the question is valid content
            if (strlen($stem) >= 20) {
                return $stem;
            }
        }

        return null;
    }

    /**
     * Extract the clinical scenario/vignette (everything before the actual question).
     */
    private function extractScenario(string $fullText): ?string
    {
        // Split by lines to find where the question starts
        $lines = explode("\n", $fullText);
        $scenarioLines = [];

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // Stop when we find a line that looks like a question
            // Questions typically:
            // - End with ?
            // - Start with question words (What, Which, How, etc.)
            // - Are relatively short (questions are usually 1-2 lines)
            if (preg_match('/^(What|Which|How|Why|When|Where|Who|.*\?)$/i', $trimmedLine)) {
                break; // Found the question, stop collecting scenario
            }

            // If line is not empty and doesn't look like a question, it's part of scenario
            if (!empty($trimmedLine) && !preg_match('/^\s*[A-E][\.:]\s/', $trimmedLine)) {
                $scenarioLines[] = $trimmedLine;
            }
        }

        if (empty($scenarioLines)) {
            return null;
        }

        $scenario = implode("\n", $scenarioLines);
        $scenario = preg_replace('/[ \t]+/', ' ', $scenario) ; // Normalize spaces
        $scenario = preg_replace('/\n{3,}/', "\n\n", $scenario); // Max 2 line breaks
        $scenario = trim($scenario);

        // Only return if it's substantial
        return strlen($scenario) > 30 ? $scenario : null;
    }

    /**
     * Extract just the question text (the part with the question mark or question words).
     */
    private function extractQuestionText(string $fullText): ?string
    {
        // Split by lines to find the question
        $lines = explode("\n", $fullText);
        $questionLines = [];
        $foundQuestion = false;

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // Skip empty lines and option markers
            if (empty($trimmedLine) || preg_match('/^\s*[A-E][\.:]\s/', $trimmedLine)) {
                if ($foundQuestion) {
                    break; // We've passed the question, stop
                }
                continue;
            }

            // Check if this line looks like a question
            if (preg_match('/^(What|Which|How|Why|When|Where|Who|.*\?)/i', $trimmedLine)) {
                $foundQuestion = true;
                $questionLines[] = $trimmedLine;
            } elseif ($foundQuestion) {
                // If we already found the question, continue collecting until we hit options
                if (!preg_match('/^\s*[A-E][\.:]\s/', $trimmedLine)) {
                    $questionLines[] = $trimmedLine;
                } else {
                    break;
                }
            }
        }

        if (empty($questionLines)) {
            // Fallback: look for any line ending with ?
            foreach ($lines as $line) {
                $trimmedLine = trim($line);
                if (preg_match('/\?$/', $trimmedLine) && strlen($trimmedLine) > 10) {
                    return $trimmedLine;
                }
            }
            return null;
        }

        $question = implode(' ', $questionLines);
        $question = preg_replace('/[ \t]+/', ' ', $question);
        return trim($question);
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
            '/Correct\s+Answer[:\s]+([A-E])\./i',  // "Correct Answer: D." format
            '/Correct\s+Answer[:\s]+([A-E])/i',   // "Correct Answer: D" format
            '/The\s+(?:most\s+)?(?:appropriate|correct|best|next\s+best\s+step)\s+(?:answer|step|treatment|initial\s+treatment|next\s+step)[:\s]+([A-E])/i',
            '/The\s+next\s+best\s+step\s+is\s+([A-E])/i',
            '/correct\s+answer[:\s]+([A-E])/i',
            '/answer[:\s]+([A-E])/i',
            '/is\s+([A-E])\./i',
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
        // First, try to extract the full explanation including "Rationale Against Incorrect Answers"
        // Handle variations: "Rationale", "Rationale for the Correct Answer", "Rationale for Correct Answer"
        $explanation = null;

        // Try to match "Rationale for the Correct Answer" or "Rationale for Correct Answer" first
        if (preg_match('/Rationale\s+for\s+(?:the\s+)?(?:Correct\s+)?Answer[:\s]+(.*?)(?=Rationale\s+Against|References|$)/is', $block, $match)) {
            $explanation = trim($match[1]);
        }
        // Fallback to just "Rationale"
        elseif (preg_match('/Rationale[:\s]+(.*?)(?=Rationale\s+Against|References|$)/is', $block, $match)) {
            $explanation = trim($match[1]);
        }

        if ($explanation) {
            // Include "Rationale Against Incorrect Answers" if it exists as a separate section
            if (preg_match('/Rationale\s+Against\s+Incorrect\s+Answers[:\s]+(.*?)(?=References|$)/is', $block, $incorrectMatch)) {
                $explanation .= "\n\nRationale Against Incorrect Answers:\n" . trim($incorrectMatch[1]);
            }

            // Clean up the explanation
            $explanation = preg_replace('/^Why\s+[A-E]\s+is\s+the\s+correct\s+answer[:\s]*/i', '', $explanation);
            // Remove answer letter prefixes like "D. Duchenne muscular dystrophy."
            $explanation = preg_replace('/^[A-E]\.\s+[^\n]+\.\s*/i', '', $explanation);
            // Normalize multiple spaces to single space (but preserve line breaks)
            $explanation = preg_replace('/[ \t]+/', ' ', $explanation);
            // Normalize multiple line breaks to double line break
            $explanation = preg_replace('/\n{3,}/', "\n\n", $explanation);
            // Clean up spaces around line breaks
            $explanation = preg_replace('/\n\s+/', "\n", $explanation);
            $explanation = preg_replace('/\s+\n/', "\n", $explanation);

            if (strlen($explanation) > 50) {
                return trim($explanation);
            }
        }

        // Fallback patterns
        $patterns = [
            '/Rationale for[:\s]+(.*?)(?=References|Rationale Against|$)/is',
            '/Rationale for Management[:\s]+(.*?)(?=References|Rationale Against|$)/is',
            '/Explanation[:\s]+(.*?)(?=References|Rationale Against|$)/is',
            '/This\s+(?:is|patient|scenario|diagnosis)(.*?)(?=References|Rationale Against|$)/is',
            '/The\s+(?:most\s+)?(?:appropriate|correct|best|likely)(.*?)(?=References|Rationale Against|$)/is',
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
