<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['salesforce_leads', 'campaign_salesforce_leads'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('source_origin_new', 255)->nullable();
                $table->string('medium_origin_new', 255)->nullable();
                $table->string('channel_new', 255)->nullable();
                $table->string('delegation_origin_new', 255)->nullable();
                $table->string('utm_campaign_new', 70)->nullable();
                $table->string('utm_id_new', 70)->nullable();
                $table->string('utm_source_new', 70)->nullable();
                $table->string('utm_medium_new', 70)->nullable();
                $table->string('utm_content_new', 70)->nullable();
                $table->string('acquired_source_legacy', 255)->nullable();
                $table->string('acquired_medium_legacy', 255)->nullable();
                $table->json('field_resolution')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn([
                    'source_origin_new',
                    'medium_origin_new',
                    'channel_new',
                    'delegation_origin_new',
                    'utm_campaign_new',
                    'utm_id_new',
                    'utm_source_new',
                    'utm_medium_new',
                    'utm_content_new',
                    'acquired_source_legacy',
                    'acquired_medium_legacy',
                    'field_resolution',
                ]);
            });
        }
    }
};
