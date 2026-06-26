<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_access_settings')) {
            return;
        }

        Schema::create('report_access_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('report_key', 64)->unique();
            $table->string('minimum_role', 32)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_access_settings');
    }
};
