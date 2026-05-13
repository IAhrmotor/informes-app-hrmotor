<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_leads', function (Blueprint $table) {
            $table->id();
            $table->string('salesforce_id')->unique();
            $table->string('name')->nullable();
            $table->dateTime('created_date')->index('sf_leads_created_idx');
            $table->date('last_activity_date')->nullable();
            $table->string('status')->nullable()->index('sf_leads_status_idx');
            $table->string('owner_id')->nullable()->index('sf_leads_owner_idx');
            $table->string('owner_name')->nullable();
            $table->string('persona_que_trabajo_id')->nullable();
            $table->string('persona_que_trabajo_name')->nullable();
            $table->string('propietario_descarte_id')->nullable();
            $table->string('propietario_descarte_name')->nullable();
            $table->dateTime('fecha_asignacion')->nullable()->index('sf_leads_asign_idx');
            $table->string('fuente_origen')->nullable();
            $table->string('medio_origen')->nullable();
            $table->string('portal_text')->nullable()->index('sf_leads_portal_idx');
            $table->string('delegacion_encargada_text')->nullable()->index('sf_leads_deleg_idx');
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_leads');
    }
};
