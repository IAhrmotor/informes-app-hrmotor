<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_users', function (Blueprint $table) {
            $table->id();
            $table->string('salesforce_id')->unique();
            $table->string('name')->nullable();
            $table->string('profile_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_users');
    }
};
