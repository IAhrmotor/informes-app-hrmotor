<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_search_console_dimension_metrics', function (Blueprint $table): void {
            $table->id();
            $table->string('property', 255);
            $table->unsignedSmallInteger('period_days');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('dimension_type', 16);
            $table->string('country_scope', 8);
            $table->unsignedInteger('rank');
            $table->text('dimension_value');
            $table->char('dimension_hash', 64);
            $table->string('brand_segment', 16)->nullable();
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->decimal('ctr', 12, 8)->nullable();
            $table->decimal('position', 12, 4)->nullable();
            $table->string('source_timezone', 64);
            $table->dateTime('extracted_at');
            $table->timestamps();

            $table->unique(
                ['property', 'period_days', 'dimension_type', 'country_scope', 'dimension_hash'],
                'seo_sc_dimension_identity_uq'
            );
            $table->index(['property', 'period_days', 'dimension_type', 'country_scope', 'rank'], 'seo_sc_dimension_ranking_idx');
            $table->index(['period_end', 'dimension_type'], 'seo_sc_dimension_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_search_console_dimension_metrics');
    }
};
