<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
