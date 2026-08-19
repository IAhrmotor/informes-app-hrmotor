<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_technical_urls', function (Blueprint $table): void {
            $table->id();
            $table->text('url');
            $table->char('url_hash', 64)->unique('seo_technical_urls_hash_uq');
            $table->string('host', 255);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_strategic')->default(false);
            $table->boolean('is_search_console')->default(false);
            $table->unsignedInteger('search_console_rank')->nullable();
            $table->unsignedBigInteger('search_console_clicks')->nullable();
            $table->unsignedBigInteger('search_console_impressions')->nullable();
            $table->boolean('in_sitemap')->nullable();
            $table->text('sitemap_url')->nullable();
            $table->dateTime('first_selected_at');
            $table->dateTime('last_selected_at');
            $table->dateTime('sitemap_checked_at')->nullable();
            $table->timestamps();

            $table->index('is_active', 'seo_technical_urls_active_idx');
            $table->index('host', 'seo_technical_urls_host_idx');
            $table->index(['is_search_console', 'search_console_rank'], 'seo_technical_urls_sc_rank_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_technical_urls');
    }
};
