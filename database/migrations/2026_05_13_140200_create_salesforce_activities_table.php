<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_activities', function (Blueprint $table) {
            $table->id();
            $table->string('salesforce_id')->unique();
            $table->string('lead_salesforce_id')->index('sf_act_lead_idx');
            $table->string('activity_kind')->index('sf_act_kind_idx');
            $table->string('owner_id')->nullable()->index('sf_act_owner_idx');
            $table->string('owner_name')->nullable();
            $table->string('created_by_id')->nullable();
            $table->string('created_by_name')->nullable();
            $table->dateTime('created_date')->index('sf_act_created_idx');
            $table->date('activity_date')->nullable();
            $table->string('subject')->nullable();
            $table->string('type')->nullable();
            $table->string('status')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_activities');
    }
};
