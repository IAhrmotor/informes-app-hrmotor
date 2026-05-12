<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_portals', function (Blueprint $table) {
            $table->id();
            $table->string('portal_original')->unique();
            $table->string('portal_group');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('portal_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_portals');
    }
};