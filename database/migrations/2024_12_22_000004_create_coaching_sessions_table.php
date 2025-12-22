<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('coaching_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('exam_attempts')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // started_at + 15 minutes
            $table->enum('status', ['in_progress', 'completed', 'expired', 'paused'])->default('in_progress');
            $table->unsignedTinyInteger('questions_reviewed')->default(0);
            $table->foreignId('current_question_id')->nullable()->constrained('questions')->onDelete('set null');
            $table->enum('current_step', [
                'initial_reasoning',  // AI asks user to explain reasoning
                'guideline_reveal',   // AI shows correct answer and guideline
                'corrected_reasoning', // User explains correct reasoning
                'follow_up',          // AI asks follow-up questions
                'complete'            // Move to next question
            ])->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('total_pause_seconds')->default(0);
            $table->timestamps();

            // Indexes
            $table->index('attempt_id');
            $table->index('user_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coaching_sessions');
    }
};
