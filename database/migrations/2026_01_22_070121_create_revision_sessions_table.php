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
        Schema::create('revision_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->nullable()->constrained('exam_attempts')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->json('selected_topics'); // Array of selected topics
            $table->json('question_ids'); // Array of question IDs in order
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->foreignId('current_question_id')->nullable()->constrained('questions')->onDelete('set null');
            $table->unsignedInteger('current_question_index')->default(0); // Position in question list (0-based)
            $table->unsignedInteger('total_questions')->default(0);
            $table->unsignedInteger('questions_answered')->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->enum('status', ['in_progress', 'paused', 'completed'])->default('in_progress');
            $table->timestamps();

            // Indexes
            $table->index('attempt_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('current_question_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('revision_sessions');
    }
};
