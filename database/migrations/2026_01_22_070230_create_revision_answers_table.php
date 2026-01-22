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
        Schema::create('revision_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('revision_sessions')->onDelete('cascade');
            $table->foreignId('question_id')->constrained()->onDelete('cascade');
            $table->enum('selected_option', ['A', 'B', 'C', 'D', 'E'])->nullable();
            $table->boolean('is_correct')->nullable();
            $table->unsignedInteger('time_taken_seconds')->default(0); // Time taken to answer (max 60 seconds)
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('session_id');
            $table->index('question_id');
            $table->unique(['session_id', 'question_id']); // One answer per question per session
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('revision_answers');
    }
};
