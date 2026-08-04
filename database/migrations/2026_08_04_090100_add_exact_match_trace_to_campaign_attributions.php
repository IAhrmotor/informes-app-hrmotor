<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_attributions', function (Blueprint $table): void {
            $table->string('matched_source_field')->nullable()->after('campaign_source_type');
            $table->string('matched_source_value')->nullable()->after('matched_source_field');
            $table->string('matched_platform_field')->nullable()->after('matched_source_value');
            $table->string('matched_platform_value')->nullable()->after('matched_platform_field');
            $table->unsignedInteger('match_candidate_count')->default(1)->after('matched_platform_value');
        });

        Schema::table('campaign_lead_attributions', function (Blueprint $table): void {
            $table->string('matched_source_field')->nullable()->after('campaign_source_type');
            $table->string('matched_source_value')->nullable()->after('matched_source_field');
            $table->string('matched_platform_field')->nullable()->after('matched_source_value');
            $table->string('matched_platform_value')->nullable()->after('matched_platform_field');
            $table->unsignedInteger('match_candidate_count')->default(1)->after('matched_platform_value');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_attributions', function (Blueprint $table): void {
            $table->dropColumn([
                'matched_source_field',
                'matched_source_value',
                'matched_platform_field',
                'matched_platform_value',
                'match_candidate_count',
            ]);
        });

        Schema::table('campaign_lead_attributions', function (Blueprint $table): void {
            $table->dropColumn([
                'matched_source_field',
                'matched_source_value',
                'matched_platform_field',
                'matched_platform_value',
                'match_candidate_count',
            ]);
        });
    }
};
