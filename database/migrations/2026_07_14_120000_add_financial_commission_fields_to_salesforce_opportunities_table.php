<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            if (! Schema::hasColumn('salesforce_opportunities', 'financial_commission')) {
                $table->decimal('financial_commission', 14, 2)->nullable()->after('importe_financiado');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'financial_discount')) {
                $table->decimal('financial_discount', 14, 2)->nullable()->after('financial_commission');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'interest_rate')) {
                $table->string('interest_rate')->nullable()->after('financial_discount');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'financial_zone')) {
                $table->string('financial_zone')->nullable()->index('sf_opps_financial_zone_idx')->after('interest_rate');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'opportunity_record_type_formula')) {
                $table->string('opportunity_record_type_formula')->nullable()->after('financial_zone');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'financing_paid')) {
                $table->boolean('financing_paid')->nullable()->after('opportunity_record_type_formula');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'financing_paid_date')) {
                $table->date('financing_paid_date')->nullable()->after('financing_paid');
            }

            if (! Schema::hasColumn('salesforce_opportunities', 'financed_amount_ratio')) {
                $table->decimal('financed_amount_ratio', 14, 6)->nullable()->after('financing_paid_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            try {
                $table->dropIndex('sf_opps_financial_zone_idx');
            } catch (Throwable) {
            }

            foreach ([
                'financed_amount_ratio',
                'financing_paid_date',
                'financing_paid',
                'opportunity_record_type_formula',
                'financial_zone',
                'interest_rate',
                'financial_discount',
                'financial_commission',
            ] as $column) {
                if (Schema::hasColumn('salesforce_opportunities', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
