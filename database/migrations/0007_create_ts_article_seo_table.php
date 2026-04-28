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
        return config('trending-summary.table_prefix', 'ts_') . 'article_seo';
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
            $table->string('meta_title', 200);
            $table->string('meta_description', 500);
            $table->string('slug', 200);
            $table->string('canonical_url', 2048)->nullable();
            $table->json('og_data');
            $table->json('twitter_data');
            $table->json('json_ld');
            $table->string('focus_keyword', 100);
            $table->json('secondary_keywords');
            $table->text('direct_answer_block')->nullable();
            $table->json('faq_items')->nullable();
            $table->json('faq_schema')->nullable();
            $table->json('aio_checklist')->nullable();
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
