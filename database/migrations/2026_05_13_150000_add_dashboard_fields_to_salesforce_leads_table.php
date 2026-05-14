<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_leads', function (Blueprint $table) {
            $table->string('medio_nuevo')->nullable()->after('medio_origen')->index('sf_leads_medio_nuevo_idx');
            $table->string('fuente_nuevo')->nullable()->after('medio_nuevo')->index('sf_leads_fuente_nuevo_idx');
            $table->string('remitente_lead')->nullable()->after('fuente_nuevo')->index('sf_leads_remitente_idx');
            $table->string('delegacion_encargada_bueno')->nullable()->after('delegacion_encargada_text');
            $table->string('delegacion_encargada')->nullable()->after('delegacion_encargada_bueno');
            $table->string('delegacion_original')->nullable()->after('delegacion_encargada');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_leads', function (Blueprint $table) {
            $table->dropIndex('sf_leads_medio_nuevo_idx');
            $table->dropIndex('sf_leads_fuente_nuevo_idx');
            $table->dropIndex('sf_leads_remitente_idx');
            $table->dropColumn([
                'medio_nuevo',
                'fuente_nuevo',
                'remitente_lead',
                'delegacion_encargada_bueno',
                'delegacion_encargada',
                'delegacion_original',
            ]);
        });
    }
};
