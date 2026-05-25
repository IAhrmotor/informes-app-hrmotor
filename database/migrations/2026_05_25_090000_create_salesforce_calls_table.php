<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_calls', function (Blueprint $table) {
            $table->id();
            $table->string('salesforce_id')->unique();
            $table->text('subject')->nullable();
            $table->longText('description')->nullable();
            $table->string('type')->nullable()->index();
            $table->string('status')->nullable()->index();
            $table->string('priority')->nullable();
            $table->date('activity_date')->nullable()->index();
            $table->dateTime('created_date')->nullable()->index();
            $table->dateTime('last_modified_date')->nullable();
            $table->string('owner_id')->nullable()->index();
            $table->string('owner_name')->nullable()->index();
            $table->string('owner_profile_name')->nullable()->index();
            $table->string('who_id')->nullable()->index();
            $table->string('who_type')->nullable()->index();
            $table->string('what_id')->nullable()->index();
            $table->string('call_object')->nullable()->index();
            $table->integer('call_duration_seconds')->nullable();
            $table->integer('parsed_duration_seconds')->nullable();
            $table->integer('adjusted_duration_seconds')->nullable();
            $table->string('call_type_raw')->nullable()->index();
            $table->string('direction')->nullable()->index();
            $table->string('portales_raw')->nullable()->index();
            $table->string('call_origin')->nullable()->index();
            $table->string('portal_resolved')->nullable()->index();
            $table->string('portal_resolution_source')->nullable()->index();
            $table->string('result_raw')->nullable()->index();
            $table->string('call_status')->nullable()->index();
            $table->boolean('is_answered')->default(false)->index();
            $table->boolean('is_lost')->default(false)->index();
            $table->string('fixed_phone')->nullable();
            $table->string('client_phone')->nullable();
            $table->string('destination_raw')->nullable();
            $table->string('destination_agent_code')->nullable()->index();
            $table->string('destination_agent_name')->nullable()->index();
            $table->string('operational_user_id')->nullable()->index();
            $table->string('operational_user_name')->nullable()->index();
            $table->string('operational_team')->nullable()->index();
            $table->string('owner_team')->nullable()->index();
            $table->string('delegation')->nullable()->index();
            $table->string('zone')->nullable()->index();
            $table->string('queue_raw')->nullable()->index();
            $table->string('uid_raw')->nullable()->index();
            $table->string('puid_raw')->nullable()->index();
            $table->dateTime('call_started_at')->nullable();
            $table->dateTime('call_ended_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('parse_debug')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_calls');
    }
};
