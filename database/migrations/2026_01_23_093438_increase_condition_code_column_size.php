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
        Schema::table('questions', function (Blueprint $table) {
            // Increase column sizes to accommodate longer condition codes and clinical presentations
            // e.g., "CC7. Diseases of the Arteries" or "CP1. Chest pain (non-traumatic)"
            $table->string('condition_code', 150)->nullable()->change();
            $table->string('clinical_presentation', 150)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('condition_code', 50)->nullable()->change();
            $table->string('clinical_presentation', 50)->nullable()->change();
        });
    }
};
