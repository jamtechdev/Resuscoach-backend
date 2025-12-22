<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    use HasFactory;

    protected $fillable = [
        'stem',
        'option_a',
        'option_b',
        'option_c',
        'option_d',
        'option_e',
        'correct_option',
        'explanation',
        'topic',
        'subtopic',
        'guideline_excerpt',
        'guideline_source',
        'difficulty',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
     * Get the coaching sessions currently on this question.
     */
    public function coachingSessions(): HasMany
    {
        return $this->hasMany(CoachingSession::class, 'current_question_id');
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
}
