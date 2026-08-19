<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_technical_url_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seo_technical_url_id')->constrained('seo_technical_urls')->cascadeOnDelete();
            $table->date('check_date');
            $table->dateTime('checked_at');
            $table->text('final_url')->nullable();
            $table->char('final_url_hash', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedSmallInteger('redirect_count')->default(0);
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->string('content_type', 191)->nullable();
            $table->boolean('is_html')->nullable();
            $table->string('meta_robots', 512)->nullable();
            $table->string('x_robots_tag', 512)->nullable();
            $table->boolean('has_noindex')->nullable();
            $table->text('canonical_url')->nullable();
            $table->char('canonical_url_hash', 64)->nullable();
            $table->unsignedSmallInteger('canonical_count')->default(0);
            $table->boolean('canonical_matches_final')->nullable();
            $table->boolean('body_truncated')->default(false);
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();

            $table->unique(['seo_technical_url_id', 'check_date'], 'seo_technical_checks_daily_uq');
            $table->index('check_date', 'seo_technical_checks_date_idx');
            $table->index('http_status', 'seo_technical_checks_status_idx');
            $table->index('error_code', 'seo_technical_checks_error_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_technical_url_checks');
    }
};
