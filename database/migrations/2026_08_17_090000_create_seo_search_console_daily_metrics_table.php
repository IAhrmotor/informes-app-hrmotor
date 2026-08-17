<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_search_console_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->string('property', 255);
            $table->date('data_date');
            $table->string('country_scope', 8);
            $table->string('brand_segment', 16);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->decimal('ctr', 12, 8)->nullable();
            $table->decimal('position', 12, 4)->nullable();
            $table->string('source_timezone', 64);
            $table->boolean('is_final')->default(true);
            $table->dateTime('extracted_at');
            $table->timestamps();

            $table->unique(['property', 'data_date', 'country_scope', 'brand_segment'], 'seo_sc_daily_identity_uq');
            $table->index('data_date', 'seo_sc_daily_date_idx');
            $table->index(['property', 'data_date'], 'seo_sc_daily_property_date_idx');
            $table->index(['country_scope', 'brand_segment', 'data_date'], 'seo_sc_daily_scope_segment_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_search_console_daily_metrics');
    }
};
