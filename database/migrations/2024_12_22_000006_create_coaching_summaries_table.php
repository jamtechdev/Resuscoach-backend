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
        Schema::create('coaching_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('coaching_sessions')->onDelete('cascade');
            $table->foreignId('attempt_id')->constrained('exam_attempts')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->json('questions_reviewed'); // List of questions reviewed with details
            $table->json('key_learning_points'); // Main learning points
            $table->json('guidelines_referenced'); // List of guidelines used
            $table->text('overall_feedback'); // AI overall feedback text
            $table->timestamps();

            // Indexes
            $table->index('session_id');
            $table->index('attempt_id');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coaching_summaries');
    }
};
