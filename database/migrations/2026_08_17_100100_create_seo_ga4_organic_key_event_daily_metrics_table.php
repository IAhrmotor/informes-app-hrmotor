<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_ga4_organic_key_event_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->string('property_id', 64);
            $table->date('data_date');
            $table->string('country_scope', 8);
            $table->text('event_name');
            $table->char('event_hash', 64);
            $table->decimal('key_events', 18, 6)->default(0);
            $table->string('source_timezone', 64);
            $table->dateTime('extracted_at');
            $table->timestamps();

            $table->unique(
                ['property_id', 'data_date', 'country_scope', 'event_hash'],
                'seo_ga4_event_identity_uq'
            );
            $table->index(['property_id', 'data_date'], 'seo_ga4_event_property_date_idx');
            $table->index(['property_id', 'country_scope', 'data_date'], 'seo_ga4_event_scope_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_ga4_organic_key_event_daily_metrics');
    }
};
