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
        return config('trending-summary.table_prefix', 'ts_') . 'article_images';
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
            $table->string('url', 2048);
            $table->string('thumbnail_url', 2048)->nullable();
            $table->string('source_provider', 50)->nullable();
            $table->string('caption', 500)->nullable();
            $table->json('versions')->nullable();
            $table->boolean('needs_manual')->default(false);
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
