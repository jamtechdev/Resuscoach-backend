<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Question extends Model
{
    use HasFactory;

    protected $fillable = [
        'scenario',
        'stem',
        'option_a',
        'option_b',
        'option_c',
        'option_d',
        'option_e',
        'correct_option',
        'explanation',
        'clinical_presentation',
        'condition_code',
        'question_number',
        'topic',
        'subtopic',
        'guideline_reference',
        'guideline_excerpt',
        'guideline_source',
        'guideline_url',
        'references',
        'image_url',
        'has_image',
        'difficulty',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'has_image' => 'boolean',
            'question_number' => 'integer',
            'references' => 'array',
        ];
    }

    /**
     * Get the exam answers for this question.
     */
    public function examAnswers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class);
    }

    /**
     * Get the coaching dialogues for this question.
     */
    public function coachingDialogues(): HasMany
    {
        return $this->hasMany(CoachingDialogue::class);
    }


    /**
     * Scope to get only active questions.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by topic.
     */
    public function scopeByTopic($query, string $topic)
    {
        return $query->where('topic', $topic);
    }

    /**
     * Scope to filter by difficulty.
     */
    public function scopeByDifficulty($query, string $difficulty)
    {
        return $query->where('difficulty', $difficulty);
    }

    /**
     * Scope to filter by clinical presentation.
     */
    public function scopeByClinicalPresentation($query, string $clinicalPresentation)
    {
        return $query->where('clinical_presentation', $clinicalPresentation);
    }

    /**
     * Scope to filter by condition code.
     */
    public function scopeByConditionCode($query, string $conditionCode)
    {
        return $query->where('condition_code', $conditionCode);
    }

    /**
     * Get all options as an array.
     */
    public function getOptionsAttribute(): array
    {
        return [
            'A' => $this->option_a,
            'B' => $this->option_b,
            'C' => $this->option_c,
            'D' => $this->option_d,
            'E' => $this->option_e,
        ];
    }

    /**
     * Get formatted references array.
     */
    public function getFormattedReferencesAttribute(): array
    {
        return $this->references ?? [];
    }

    /**
     * Public URL for question images (uploads live on the public disk).
     * Uses filesystem disk URL (APP_URL + /storage) so links stay consistent with php artisan storage:link.
     */
    public function getImageUrlAttribute($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (Str::startsWith($value, ['http://', 'https://'])) {
            $storageBase = rtrim((string) config('filesystems.disks.public.url'), '/');
            $parsed = parse_url($value);
            $path = is_array($parsed) ? ($parsed['path'] ?? '') : '';
            if (
                $storageBase !== ''
                && is_string($path)
                && str_starts_with($path, '/storage/')
            ) {
                $relative = ltrim(substr($path, strlen('/storage')), '/');
                $query = isset($parsed['query']) && is_string($parsed['query'])
                    ? '?' . $parsed['query']
                    : '';

                return "{$storageBase}/{$relative}{$query}";
            }

            return $value;
        }

        $path = ltrim($value, '/');
        if (Str::startsWith($path, 'storage/')) {
            $path = Str::after($path, 'storage/');
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        $base = rtrim((string) config('filesystems.disks.public.url'), '/');
        if ($base === '') {
            $appUrl = rtrim((string) config('app.url'), '/');
            $base = $appUrl !== '' ? "{$appUrl}/storage" : '';
        }

        return $base !== '' ? "{$base}/{$path}" : "/storage/{$path}";
    }
}
