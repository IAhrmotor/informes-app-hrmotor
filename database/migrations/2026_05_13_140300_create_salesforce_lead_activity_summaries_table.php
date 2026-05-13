<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_lead_activity_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('lead_salesforce_id')->unique();
            $table->unsignedInteger('total_actividades')->default(0);
            $table->unsignedInteger('total_tasks')->default(0);
            $table->unsignedInteger('total_events')->default(0);
            $table->dateTime('fecha_primer_contacto')->nullable();
            $table->dateTime('fecha_ultima_actividad')->nullable();
            $table->string('primer_contacto_activity_id')->nullable();
            $table->string('primer_contacto_tipo')->nullable();
            $table->string('primer_contacto_subject')->nullable();
            $table->string('primer_contacto_owner_id')->nullable();
            $table->string('primer_contacto_owner_name')->nullable();
            $table->string('primer_contacto_created_by_id')->nullable();
            $table->string('primer_contacto_created_by_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_lead_activity_summaries');
    }
};
