<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Get the table name with configurable prefix.
     */
    private function tableName(): string
    {
        return config('trending-summary.table_prefix', 'ts_') . 'article_subtitles';
    }

    /**
     * Get the trending articles table name with configurable prefix.
     */
    private function articlesTable(): string
    {
        return config('trending-summary.table_prefix', 'ts_') . 'trending_articles';
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create($this->tableName(), function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')
                ->constrained($this->articlesTable())
                ->onDelete('cascade');
            $table->string('original_language', 10);
            $table->enum('source_format', ['srt', 'vtt', 'manual']);
            $table->json('original_cues');
            $table->json('translated_cues')->nullable();
            $table->enum('translation_status', [
                'pending',
                'translating',
                'translated',
                'reviewed',
                'failed',
            ])->default('pending');
            $table->string('srt_path', 500)->nullable();
            $table->string('vtt_path', 500)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->tableName());
    }
};
