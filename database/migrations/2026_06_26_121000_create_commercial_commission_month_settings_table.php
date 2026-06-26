<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commercial_commission_month_settings')) {
            return;
        }

        Schema::create('commercial_commission_month_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('month', 7)->unique();
            $table->json('settings');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_commission_month_settings');
    }
};
