<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads_raw', function (Blueprint $table) {
            $table->id();

            $table->string('salesforce_id')->nullable()->unique();
            $table->timestamp('lead_created_at')->nullable();

            $table->string('status')->nullable();
            $table->string('owner_id')->nullable();
            $table->string('owner_name')->nullable();

            $table->string('medio_nuevo')->nullable();
            $table->string('fuente_nuevo')->nullable();

            $table->string('portal')->nullable();
            $table->string('lea_sel_fuente_origen')->nullable();
            $table->string('lea_sel_medio_origen')->nullable();

            $table->string('remitente_lead')->nullable();

            $table->string('delegacion_encargada_text')->nullable();
            $table->string('delegacion_encargada_bueno')->nullable();
            $table->string('delegacion_encargada')->nullable();
            $table->string('delegacion')->nullable();

            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('first_task_event_at')->nullable();
            $table->timestamp('last_task_event_at')->nullable();

            $table->json('raw_payload')->nullable();

            $table->timestamps();

            $table->index('lead_created_at');
            $table->index('status');
            $table->index('medio_nuevo');
            $table->index('fuente_nuevo');
            $table->index('remitente_lead');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads_raw');
    }
};