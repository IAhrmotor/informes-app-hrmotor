<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_rule_validities', function (Blueprint $table) {
            $table->id();
            $table->string('portal_original');
            $table->string('rule_name');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['portal_original', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_rule_validities');
    }
};