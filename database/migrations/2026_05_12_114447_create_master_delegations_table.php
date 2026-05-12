<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_delegations', function (Blueprint $table) {
            $table->id();
            $table->string('delegation_name')->unique();
            $table->string('commercial_group');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('commercial_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_delegations');
    }
};