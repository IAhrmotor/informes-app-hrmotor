<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_platform_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->string('unique_key', 64)->unique();
            $table->string('platform')->index();
            $table->string('account_id')->nullable()->index();
            $table->string('campaign_id')->nullable()->index();
            $table->string('campaign_name')->nullable()->index();
            $table->string('adset_id')->nullable()->index();
            $table->string('adset_name')->nullable();
            $table->string('ad_group_id')->nullable()->index();
            $table->string('ad_group_name')->nullable();
            $table->string('ad_id')->nullable()->index();
            $table->string('ad_name')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_platform_identifiers');
    }
};
