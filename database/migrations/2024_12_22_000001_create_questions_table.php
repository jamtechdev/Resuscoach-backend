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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->text('stem'); // Question text/vignette
            $table->text('option_a');
            $table->text('option_b');
            $table->text('option_c');
            $table->text('option_d');
            $table->text('option_e');
            $table->enum('correct_option', ['A', 'B', 'C', 'D', 'E']);
            $table->text('explanation');

            // Hierarchical organization
            $table->string('clinical_presentation', 50)->nullable(); // e.g., "CP1. Chest pain"
            $table->string('condition_code', 50)->nullable(); // e.g., "CC1. Acute Coronary Syndromes"
            $table->unsignedInteger('question_number')->nullable(); // Order within condition

            // Topic classification
            $table->string('topic'); // Main topic (e.g., "Cardiology")
            $table->string('subtopic')->nullable(); // Sub-topic (e.g., "ACS")
            $table->enum('difficulty', ['Easy', 'Medium', 'Hard'])->default('Medium');

            // Guideline references
            $table->text('guideline_reference')->nullable(); // Full guideline reference text
            $table->text('guideline_excerpt')->nullable(); // Text from official guideline
            $table->string('guideline_source')->nullable(); // Name of guideline (e.g., "NICE CG95")
            $table->string('guideline_url', 500)->nullable(); // URL to guideline

            // References (stored as JSON array)
            $table->json('references')->nullable(); // Array of reference objects with title and URL

            // Media support
            $table->string('image_url', 500)->nullable(); // URL to ECG/image if applicable
            $table->boolean('has_image')->default(false); // Quick filter for questions with images

            // Status
            $table->boolean('is_active')->default(true); // Whether question is available for exams
            $table->timestamps();

            // Indexes for filtering
            $table->index('topic');
            $table->index('subtopic');
            $table->index('clinical_presentation');
            $table->index('condition_code');
            $table->index('difficulty');
            $table->index('is_active');
            $table->index('has_image');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
