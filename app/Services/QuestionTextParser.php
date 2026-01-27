<?php

namespace App\Services;

class QuestionTextParser
{
    // Dynamic patterns detected from file
    protected ?string $cpPattern = null;
    protected ?string $ccPattern = null;

    public function parse(string $content, ?string $filename = null): array
    {
        $questions = [];

        // Normalize line endings first
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        // Detect topic dynamically from content or filename
        $detectedTopic = $this->detectTopic($content, $filename);

        // Detect CP/CC patterns dynamically from the content
        $this->detectPatterns($content);

        // Extract CP and CC mappings using detected patterns
        $cpMap = $this->extractCPCodes($content);
        $ccMap = $this->extractCCCodes($content);

        // Split content into sections - look for question patterns
        $lines = explode("\n", $content);

        $currentCP = null;
        $currentCC = null;
        $currentSubtopic = null;
        $currentBlock = [];
        $questionNumber = 1;

        foreach ($lines as $lineNum => $line) {
            $trimmedLine = trim($line);

            // Skip empty lines at the start of blocks
            if (empty($trimmedLine) && empty($currentBlock)) {
                continue;
            }

            // Update CP context using dynamic pattern (e.g., OptP1, CP1, NeuroP1, etc.)
            if ($this->cpPattern && preg_match('/^(' . preg_quote($this->cpPattern, '/') . ')(\d+)\.\s*(.+)$/i', $trimmedLine, $cpMatch)) {
                $currentCP = $cpMatch[1] . $cpMatch[2] . '. ' . trim($cpMatch[3]);
                $currentSubtopic = trim($cpMatch[3]); // Extract subtopic from CP line
                // If we have a block, process it before starting new CP
                if (!empty($currentBlock)) {
                    $question = $this->processBlock(implode("\n", $currentBlock), $currentCP, $currentCC, $questionNumber, $detectedTopic, $currentSubtopic);
                    if ($question) {
                        $questions[] = $question;
                        $questionNumber++;
                    }
                    $currentBlock = [];
                }
                continue;
            }

            // Update CC context using dynamic pattern (e.g., OptC1, CC1, NeuroC1, etc.)
            if ($this->ccPattern && preg_match('/^(' . preg_quote($this->ccPattern, '/') . ')(\d+)\.\s*(.+)$/i', $trimmedLine, $ccMatch)) {
                $currentCC = $ccMatch[1] . $ccMatch[2] . '. ' . trim($ccMatch[3]);
                $currentSubtopic = trim($ccMatch[3]); // Extract subtopic from CC line
                // If we have a block, process it before starting new CC
                if (!empty($currentBlock)) {
                    $question = $this->processBlock(implode("\n", $currentBlock), $currentCP, $currentCC, $questionNumber, $detectedTopic, $currentSubtopic);
                    if ($question) {
                        $questions[] = $question;
                        $questionNumber++;
                    }
                    $currentBlock = [];
                }
                continue;
            }

            // Detect start of new question block
            $isQuestionStart = false;
            if (preg_match('/^(A \d+[-–](year|month|week|day)|You are|^\d+\.\s+[A-Z]|^Question\s+\d+[:\s])/i', $trimmedLine)) {
                $isQuestionStart = true;
            }

            // If we detect a new question start and have accumulated a block
            if ($isQuestionStart && !empty($currentBlock)) {
                $blockText = implode("\n", $currentBlock);
                // Check if previous block has options (is a complete question)
                if (preg_match('/^\s*[A-E]\.\s+/m', $blockText)) {
                    $question = $this->processBlock($blockText, $currentCP, $currentCC, $questionNumber, $detectedTopic, $currentSubtopic);
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
                $question = $this->processBlock($blockText, $currentCP, $currentCC, $questionNumber, $detectedTopic, $currentSubtopic);
                if ($question) {
                    $questions[] = $question;
                }
            }
        }

        return $questions;
    }

    private function processBlock(string $block, ?string $cp, ?string $cc, int $number, string $topic, ?string $subtopic = null): ?array
    {
        return $this->extractQuestion($block, $cp, $cc, $number, $topic, $subtopic);
    }

    /**
     * Detect topic dynamically from file content or filename.
     * No hardcoded list - extracts the first capitalized word/phrase that looks like a topic.
     */
    private function detectTopic(string $content, ?string $filename = null): string
    {
        // Try to detect from the first few lines of content
        // Look for a standalone topic name at the start (single word or phrase on its own line)
        $lines = explode("\n", $content);
        
        foreach (array_slice($lines, 0, 10) as $line) {
            $trimmedLine = trim($line);
            
            // Skip empty lines
            if (empty($trimmedLine)) {
                continue;
            }
            
            // Look for a line that is just a topic name (capitalized word/phrase, not a question or CP/CC)
            // Must be a single word or short phrase (not a sentence), starts with capital letter
            if (preg_match('/^([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)$/', $trimmedLine, $match)) {
                // This looks like a topic header (e.g., "Ophthalmology", "Emergency Medicine", "Cardiology")
                return trim($match[1]);
            }
            
            // Also check for topic names followed by section markers
            if (preg_match('/^([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\s*[:–-]?\s*$/i', $trimmedLine, $match)) {
                return trim($match[1]);
            }
        }
        
        // Try to extract from filename if available
        if ($filename) {
            // Remove extension and clean up
            $name = pathinfo($filename, PATHINFO_FILENAME);
            // Convert underscores/hyphens to spaces, capitalize
            $name = str_replace(['_', '-'], ' ', $name);
            $name = ucwords(strtolower($name));
            // If it looks like a reasonable topic name, use it
            if (preg_match('/^[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*$/', $name)) {
                return $name;
            }
        }
        
        // If we still can't detect, try to find common medical specialty keywords anywhere in first 20 lines
        $firstPart = implode("\n", array_slice($lines, 0, 20));
        if (preg_match('/\b(Ophthalmology|Cardiology|Neurology|Respiratory|Trauma|Paediatrics|Pediatrics|Toxicology|Gastroenterology|Nephrology|Endocrinology|Dermatology|Rheumatology|Haematology|Hematology|Oncology|Psychiatry|Geriatrics|Obstetrics|Gynaecology|Gynecology|Urology|Orthopaedics|Orthopedics|Emergency Medicine|Critical Care|Infectious Disease|Immunology|Allergy)\b/i', $firstPart, $match)) {
            return ucfirst(strtolower($match[1]));
        }
        
        // Default fallback - use "General" instead of hardcoded topic
        return 'General';
    }

    /**
     * Detect CP and CC patterns dynamically from the content.
     * Looks for patterns like "OptP1.", "CP1.", "NeuroP1.", etc.
     */
    private function detectPatterns(string $content): void
    {
        // Reset patterns
        $this->cpPattern = null;
        $this->ccPattern = null;
        
        // Look for Clinical Presentation patterns (ends with P followed by number)
        // Examples: OptP1, CP1, CardP1, NeuroP1, etc.
        if (preg_match('/^([A-Za-z]+P)\d+\.\s+/m', $content, $match)) {
            $this->cpPattern = $match[1];
        }
        
        // Look for Condition Code patterns (ends with C followed by number)
        // Examples: OptC1, CC1, CardC1, NeuroC1, etc.
        if (preg_match('/^([A-Za-z]+C)\d+\.\s+/m', $content, $match)) {
            $this->ccPattern = $match[1];
        }
        
        // Fallback to standard CP/CC if no specific pattern found but content has them
        if (!$this->cpPattern && preg_match('/^CP\d+\.\s+/m', $content)) {
            $this->cpPattern = 'CP';
        }
        if (!$this->ccPattern && preg_match('/^CC\d+\.\s+/m', $content)) {
            $this->ccPattern = 'CC';
        }
    }

    /**
     * Extract CP codes dynamically using detected pattern.
     */
    private function extractCPCodes(string $content): array
    {
        $cpMap = [];
        
        if ($this->cpPattern) {
            $pattern = '/^' . preg_quote($this->cpPattern, '/') . '(\d+)\.\s*([^\n]+)/m';
            preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $cpMap[$match[1]] = trim($match[2]);
            }
        }
        
        return $cpMap;
    }

    /**
     * Extract CC codes dynamically using detected pattern.
     */
    private function extractCCCodes(string $content): array
    {
        $ccMap = [];
        
        if ($this->ccPattern) {
            $pattern = '/^' . preg_quote($this->ccPattern, '/') . '(\d+)\.\s*([^\n]+)/m';
            preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $ccMap[$match[1]] = trim($match[2]);
            }
        }
        
        return $ccMap;
    }

    private function extractQuestion(string $block, ?string $cp, ?string $cc, int $number, string $topic, ?string $subtopic = null): ?array
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

        // Use dynamically extracted subtopic, or try to extract from CP/CC if not provided
        $finalSubtopic = $subtopic ?? $this->extractSubtopicFromCode($cp) ?? $this->extractSubtopicFromCode($cc);

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
            'subtopic' => $finalSubtopic,
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

    /**
     * Extract subtopic dynamically from CP or CC code string.
     * E.g., "OptP1. Diplopia" -> "Diplopia"
     */
    private function extractSubtopicFromCode(?string $code): ?string
    {
        if (!$code) {
            return null;
        }
        
        // Extract the text after the code number and period
        // Pattern: "OptP1. Diplopia" or "CC1. Acute Coronary Syndromes"
        if (preg_match('/^[A-Za-z]+[PC]?\d+\.\s*(.+)$/i', $code, $match)) {
            return trim($match[1]);
        }
        
        return null;
    }

    private function extractStem(string $block): ?string
    {
        // Remove CP/CC lines from start (dynamic pattern - any prefix followed by P or C and numbers)
        $block = preg_replace('/^[A-Za-z]+[PC]\d+[^\n]*\n?/m', '', $block);
        $block = trim($block);

        // Find everything before the first option (A. or Option A)
        // This includes the full clinical scenario/vignette AND the question
        // Use a more flexible pattern that captures everything up to the first option line
        // Match: "A. ", "A.", "Option A:", etc. at start of line
        if (preg_match('/^(.*?)(?=^\s*[A-E][\.:]\s|^Option\s+[A-E][:\s]|^\s*[A-E]\.\s+[A-Z])/sm', $block, $match)) {
            $stem = trim($match[1]);

            // Remove leading question numbers (like "1. " or "Question 1:" or "a. ")
            $stem = preg_replace('/^(Question\s+)?[a-z]?[0-9]+[\.:]\s*/i', '', $stem);

            // Remove any remaining CP/CC references (dynamic pattern)
            $stem = preg_replace('/^[A-Za-z]+[PC]\d+[^\n]*\n?/m', '', $stem);

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
        $scenario = preg_replace('/[ \t]+/', ' ', $scenario); // Normalize spaces
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
        // Stop at common explanation starters: "The patient", "This patient", "The most", "Rationale", etc.
        if (preg_match_all('/^\s*([A-E])\.\s+([^\n]+(?:\n(?!^\s*[A-E]\.|Rationale|Explanation|The\s+most|The\s+patient|This\s+patient|The\s+combination|This\s+is|Correct|Answer|Why|Differentiating|References|The\s+diagnosis|The\s+clinical|The\s+key|This\s+case|This\s+scenario)[^\n]+)*)/m', $block, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $optionText = trim($match[2]);
                // Remove trailing period if it's just punctuation
                $optionText = preg_replace('/\.$/', '', $optionText);
                // Clean up whitespace
                $optionText = preg_replace('/\s+/', ' ', $optionText);
                // Remove any explanation text that might have been captured after the option
                // Options are typically short (under 200 chars) - if longer, truncate at explanation markers
                if (strlen($optionText) > 200) {
                    // Look for common explanation starters within the text
                    $explanationMarkers = [
                        'The patient',
                        'This patient',
                        'The most',
                        'The combination',
                        'This is',
                        'The diagnosis',
                        'The clinical',
                        'The key',
                        'This case',
                        'This scenario'
                    ];
                    foreach ($explanationMarkers as $marker) {
                        $pos = stripos($optionText, $marker);
                        if ($pos !== false && $pos > 5) {
                            $optionText = trim(substr($optionText, 0, $pos));
                            break;
                        }
                    }
                }
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

}
