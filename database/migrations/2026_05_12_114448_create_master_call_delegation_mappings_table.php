<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_call_delegation_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('portal_original');
            $table->string('received_value');
            $table->string('type')->nullable();
            $table->string('delegation_name')->nullable();
            $table->string('commercial_group')->nullable();
            $table->string('status')->default('active');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index(['portal_original', 'received_value'], 'call_map_portal_value_idx');
            $table->index('commercial_group', 'call_map_group_idx');
            $table->unique(['portal_original', 'received_value'], 'call_map_portal_value_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_call_delegation_mappings');
    }
};
