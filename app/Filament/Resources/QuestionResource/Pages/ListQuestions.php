<?php

namespace App\Filament\Resources\QuestionResource\Pages;

use App\Filament\Resources\QuestionResource;
use App\Imports\QuestionImport;
use App\Models\Question;
use App\Services\QuestionTextParser;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ListQuestions extends ListRecords
{
    protected static string $resource = QuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('downloadTemplate')
                ->label('Download Template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('info')
                ->action(function () {
                    $templatePath = storage_path('app/templates/questions_import_template.csv');

                    if (!file_exists($templatePath)) {
                        \Filament\Notifications\Notification::make()
                            ->title('Template Not Found')
                            ->warning()
                            ->body('Template file not found. Please contact administrator.')
                            ->send();
                        return;
                    }

                    return response()->download($templatePath, 'questions_import_template.csv', [
                        'Content-Type' => 'text/csv',
                    ]);
                })
                ->tooltip('Download CSV template file'),
            Actions\Action::make('import')
                ->label('Import Questions')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('success')
                ->form([
                    \Filament\Forms\Components\FileUpload::make('file')
                        ->label('CSV/Excel File')
                        ->acceptedFileTypes([
                            'text/csv',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.oasis.opendocument.spreadsheet',
                        ])
                        ->required()
                        ->disk('local')
                        ->directory('imports')
                        ->visibility('private')
                        ->helperText('Upload a CSV or Excel file with question data. First row should contain headers. Download the template for reference.'),
                ])
                ->action(function (array $data) {
                    try {
                        if (empty($data['file'])) {
                            throw new \Exception('No file was uploaded.');
                        }

                        // Filament FileUpload stores the file path relative to the disk
                        $storedPath = $data['file'];

                        // Try multiple path formats
                        $possiblePaths = [
                            $storedPath,
                            'imports/' . basename($storedPath),
                            'imports/' . $storedPath,
                            basename($storedPath),
                        ];

                        $filePath = null;
                        foreach ($possiblePaths as $path) {
                            if (Storage::disk('local')->exists($path)) {
                                $filePath = Storage::disk('local')->path($path);
                                break;
                            }
                        }

                        if (!$filePath || !file_exists($filePath)) {
                            // Last resort: try to read directly from Storage
                            if (Storage::disk('local')->exists($storedPath)) {
                                // Create a temporary file and write the content
                                $content = Storage::disk('local')->get($storedPath);
                                $tempFile = tempnam(sys_get_temp_dir(), 'import_');
                                file_put_contents($tempFile, $content);
                                $filePath = $tempFile;
                            } else {
                                throw new \Exception('File not found. Tried paths: ' . implode(', ', $possiblePaths));
                            }
                        }

                        $import = new QuestionImport();
                        Excel::import($import, $filePath);

                        // Clean up uploaded file
                        if (Storage::disk('local')->exists($storedPath)) {
                            Storage::disk('local')->delete($storedPath);
                        }

                        // Clean up temp file if created
                        if (isset($tempFile) && file_exists($tempFile)) {
                            @unlink($tempFile);
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('Import Successful')
                            ->success()
                            ->body('Questions have been imported successfully.')
                            ->send();
                    } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
                        $failures = $e->failures();
                        $message = 'Validation errors occurred: ';
                        foreach ($failures as $failure) {
                            $message .= "Row {$failure->row()}: " . implode(', ', $failure->errors()) . ' ';
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('Import Failed - Validation Errors')
                            ->danger()
                            ->body($message)
                            ->send();
                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->title('Import Failed')
                            ->danger()
                            ->body('Error: ' . $e->getMessage())
                            ->send();
                    }
                })
                ->modalHeading('Import Questions from File')
                ->modalDescription('Upload a CSV or Excel file to bulk import questions. Make sure the first row contains column headers matching the question fields.')
                ->modalSubmitActionLabel('Import')
                ->modalWidth('lg'),
            Actions\Action::make('importText')
                ->label('Import from Text File')
                ->icon('heroicon-o-document-text')
                ->color('warning')
                ->form([
                    \Filament\Forms\Components\FileUpload::make('file')
                        ->label('Text File (.txt)')
                        ->acceptedFileTypes(['text/plain'])
                        ->required()
                        ->disk('local')
                        ->directory('imports')
                        ->visibility('private')
                        ->helperText('Upload a text file with structured questions. The parser will extract questions, options, answers, and references automatically.'),
                ])
                ->action(function (array $data) {
                    try {
                        if (empty($data['file'])) {
                            throw new \Exception('No file was uploaded.');
                        }

                        // Get file path
                        $filePath = $data['file'];
                        if (!str_starts_with($filePath, 'imports/')) {
                            $filePath = 'imports/' . $filePath;
                        }

                        $absolutePath = Storage::disk('local')->path($filePath);

                        if (!file_exists($absolutePath)) {
                            throw new \Exception('File not found. Please try uploading again.');
                        }

                        // Read and parse the file
                        $content = file_get_contents($absolutePath);

                        // Check file encoding and convert if needed
                        if (!mb_check_encoding($content, 'UTF-8')) {
                            $content = mb_convert_encoding($content, 'UTF-8', 'auto');
                        }

                        $parser = new QuestionTextParser();
                        // Pass filename to help detect topic
                        $filename = basename($absolutePath);
                        $questions = $parser->parse($content, $filename);

                        $totalParsed = count($questions);

                        // Detect topic from filename for feedback (use the parser's detection)
                        $detectedTopic = 'Cardiology'; // Default
                        $filenameLower = strtolower($filename);
                        if (strpos($filenameLower, 'procedural') !== false) {
                            $detectedTopic = 'Procedural';
                        } elseif (strpos($filenameLower, 'cardiology') !== false) {
                            $detectedTopic = 'Cardiology';
                        } elseif (strpos($filenameLower, 'respiratory') !== false) {
                            $detectedTopic = 'Respiratory';
                        } elseif (strpos($filenameLower, 'trauma') !== false) {
                            $detectedTopic = 'Trauma';
                        }

                        // Also check the actual detected topic from parsed questions
                        if (!empty($questions) && isset($questions[0]['topic'])) {
                            $detectedTopic = $questions[0]['topic'];
                        }

                        if (empty($questions)) {
                            // Provide more detailed error information
                            $contentLength = strlen($content);
                            $hasOptions = preg_match('/^\s*[A-E]\.\s+/m', $content);
                            $hasQuestions = preg_match('/(A \d+[-–]year|What is|\?)/i', $content);
                            $contentPreview = substr($content, 0, 1000);

                            $errorMsg = "No questions could be parsed from the file.\n";
                            $errorMsg .= "File size: {$contentLength} characters\n";
                            $errorMsg .= "Contains options (A-E): " . ($hasOptions ? 'Yes' : 'No') . "\n";
                            $errorMsg .= "Contains question patterns: " . ($hasQuestions ? 'Yes' : 'No') . "\n";
                            $errorMsg .= "File preview:\n" . $contentPreview;

                            throw new \Exception($errorMsg);
                        }

                        // Import questions
                        $imported = 0;
                        $skipped = 0;
                        $errors = [];

                        foreach ($questions as $index => $questionData) {
                            try {
                                // Check if question already exists (use a more flexible match)
                                // First try exact stem match
                                $existingQuestion = Question::where('stem', $questionData['stem'])->first();

                                // If not found, try a partial match (first 100 chars) to catch similar questions
                                if (!$existingQuestion && strlen($questionData['stem']) > 50) {
                                    $stemStart = substr($questionData['stem'], 0, 100);
                                    $existingQuestion = Question::where('stem', 'like', $stemStart . '%')->first();
                                }

                                if ($existingQuestion) {
                                    // Update the topic if it's different (in case it was imported with wrong topic)
                                    if ($existingQuestion->topic !== $questionData['topic']) {
                                        $existingQuestion->update(['topic' => $questionData['topic']]);
                                        $imported++; // Count as imported since we updated it
                                    } else {
                                        $skipped++;
                                    }
                                    continue;
                                }

                                Question::create($questionData);
                                $imported++;
                            } catch (\Exception $e) {
                                $errors[] = "Question " . ($index + 1) . ": " . $e->getMessage() . " (Stem: " . substr($questionData['stem'], 0, 50) . "...)";
                                $skipped++;
                            }
                        }

                        // Clean up uploaded file
                        Storage::disk('local')->delete($filePath);

                        $message = "Import completed!\n";
                        $message .= "Detected Topic: {$detectedTopic}\n";
                        $message .= "Parsed from file: {$totalParsed}\n";
                        $message .= "Imported/Updated: {$imported}, Skipped: {$skipped}";
                        if (!empty($errors)) {
                            $message .= "\n\nErrors: " . implode("\n", array_slice($errors, 0, 5));
                            if (count($errors) > 5) {
                                $message .= "\n... and " . (count($errors) - 5) . " more errors";
                            }
                        }

                        // Warn if parsed count doesn't match imported+skipped
                        if ($totalParsed > 0 && ($imported + $skipped) < $totalParsed) {
                            $message .= "\n\n⚠️ Warning: " . ($totalParsed - $imported - $skipped) . " questions were parsed but not processed (check errors above)";
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('Text Import Completed')
                            ->success()
                            ->body($message)
                            ->send();
                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->title('Import Failed')
                            ->danger()
                            ->body('Error: ' . $e->getMessage())
                            ->send();
                    }
                })
                ->modalHeading('Import Questions from Text File')
                ->modalDescription('Upload a text file containing structured questions. The system will automatically parse and extract questions, options, answers, explanations, and references.')
                ->modalSubmitActionLabel('Import')
                ->modalWidth('lg'),
        ];
    }
}
