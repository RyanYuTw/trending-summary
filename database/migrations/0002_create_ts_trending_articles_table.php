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
        return config('trending-summary.table_prefix', 'ts_') . 'trending_articles';
    }

    /**
     * Get the templates table name with configurable prefix.
     */
    private function templatesTable(): string
    {
        return config('trending-summary.table_prefix', 'ts_') . 'article_templates';
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create($this->tableName(), function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title', 500);
            $table->string('original_title', 500);
            $table->string('original_url', 2048)->unique();
            $table->string('source_name', 100);
            $table->enum('content_type', ['article', 'video']);
            $table->longText('content_body');
            $table->longText('summary')->nullable();
            $table->string('selected_title', 500)->nullable();
            $table->enum('status', [
                'pending',
                'candidate',
                'filtered',
                'generated',
                'reviewing',
                'approved',
                'rejected',
                'published',
                'failed',
                'scheduled',
            ])->default('pending');
            $table->float('relevance_score')->default(0);
            $table->integer('quality_score')->nullable();
            $table->boolean('is_auto_published')->default(false);
            $table->foreignId('template_id')
                ->nullable()
                ->constrained($this->templatesTable())
                ->nullOnDelete();
            $table->json('trend_keywords');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // Indexes for frequently queried columns
            $table->index('status');
            $table->index('content_type');
            $table->index('source_name');
            $table->index('created_at');
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
