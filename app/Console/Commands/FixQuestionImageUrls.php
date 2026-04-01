<?php

namespace App\Console\Commands;

use App\Models\Question;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class FixQuestionImageUrls extends Command
{
    protected $signature = 'questions:fix-image-urls {--dry-run : Show changes without updating database}';

    protected $description = 'Normalize stale absolute question image URLs to local storage paths';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $rows = Question::query()
            ->whereNotNull('image_url')
            ->select(['id', 'image_url'])
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No question image URLs found.');
            return self::SUCCESS;
        }

        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $current = trim((string) $row->image_url);
            if ($current === '') {
                $skipped++;
                continue;
            }

            $normalized = $this->normalizeToStoragePath($current);
            if ($normalized === null || $normalized === $current) {
                $skipped++;
                continue;
            }

            $this->line("Question #{$row->id}: {$current} -> {$normalized}");

            if (!$dryRun) {
                Question::query()
                    ->where('id', $row->id)
                    ->update(['image_url' => $normalized]);
            }

            $updated++;
        }

        if ($dryRun) {
            $this->warn("Dry run complete. {$updated} rows would be updated, {$skipped} skipped.");
        } else {
            $this->info("Completed. {$updated} rows updated, {$skipped} skipped.");
        }

        return self::SUCCESS;
    }

    private function normalizeToStoragePath(string $value): ?string
    {
        $clean = trim($value);
        if ($clean === '') {
            return null;
        }

        // Already in desired relative storage format.
        if (Str::startsWith($clean, 'storage/')) {
            return $clean;
        }

        // Relative path variants.
        if (Str::startsWith($clean, '/storage/')) {
            return ltrim($clean, '/');
        }

        // Full URL variants: https://domain/storage/question-images/...
        if (Str::startsWith($clean, ['http://', 'https://'])) {
            $path = (string) parse_url($clean, PHP_URL_PATH);
            if ($path !== '' && Str::startsWith($path, '/storage/')) {
                return ltrim($path, '/');
            }

            return null;
        }

        return null;
    }
}
