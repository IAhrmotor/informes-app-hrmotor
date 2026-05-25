<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_agent_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('salesforce_user_id')->nullable()->index();
            $table->string('agent_code')->nullable()->index();
            $table->string('user_name')->nullable()->index();
            $table->string('normalized_name')->nullable()->index();
            $table->string('team_type')->index();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_agent_mappings');
    }
};
