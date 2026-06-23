<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            if (! Schema::hasColumn('salesforce_opportunities', 'owner_is_active')) {
                $table->boolean('owner_is_active')->default(true)->index('sf_opps_owner_active_idx')->after('owner_name');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'shared_delivery_id')) {
                $table->string('shared_delivery_id')->nullable()->index('sf_opps_shared_delivery_idx')->after('account_company_email');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'shared_delivery_name')) {
                $table->string('shared_delivery_name')->nullable()->after('shared_delivery_id');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'garantia_total')) {
                $table->decimal('garantia_total', 14, 2)->nullable()->after('shared_delivery_name');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'beneficio_financiacion_comercial')) {
                $table->decimal('beneficio_financiacion_comercial', 14, 2)->nullable()->after('garantia_total');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'importe_financiado')) {
                $table->decimal('importe_financiado', 14, 2)->nullable()->after('beneficio_financiacion_comercial');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'opo_div_descuento')) {
                $table->decimal('opo_div_descuento', 14, 2)->nullable()->after('importe_financiado');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'informe_rentabilidad')) {
                $table->decimal('informe_rentabilidad', 14, 2)->nullable()->after('opo_div_descuento');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'rentabilidad_financiera')) {
                $table->decimal('rentabilidad_financiera', 14, 6)->nullable()->after('informe_rentabilidad');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'vehicle_interest_id')) {
                $table->string('vehicle_interest_id')->nullable()->index('sf_opps_vehicle_interest_idx')->after('rentabilidad_financiera');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'vehicle_plate')) {
                $table->string('vehicle_plate')->nullable()->index('sf_opps_vehicle_plate_idx')->after('vehicle_interest_id');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'vehicle_entry_date')) {
                $table->date('vehicle_entry_date')->nullable()->after('vehicle_plate');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'vehicle_days_in_stock')) {
                $table->integer('vehicle_days_in_stock')->nullable()->index('sf_opps_vehicle_stock_days_idx')->after('vehicle_entry_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            foreach ([
                'sf_opps_owner_active_idx',
                'sf_opps_shared_delivery_idx',
                'sf_opps_vehicle_interest_idx',
                'sf_opps_vehicle_plate_idx',
                'sf_opps_vehicle_stock_days_idx',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (Throwable) {
                }
            }

            foreach ([
                'vehicle_days_in_stock',
                'vehicle_entry_date',
                'vehicle_plate',
                'vehicle_interest_id',
                'rentabilidad_financiera',
                'informe_rentabilidad',
                'opo_div_descuento',
                'importe_financiado',
                'beneficio_financiacion_comercial',
                'garantia_total',
                'shared_delivery_name',
                'shared_delivery_id',
                'owner_is_active',
            ] as $column) {
                if (Schema::hasColumn('salesforce_opportunities', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
