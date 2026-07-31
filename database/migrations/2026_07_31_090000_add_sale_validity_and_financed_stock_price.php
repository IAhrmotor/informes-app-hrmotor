<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_sale_snapshots', function (Blueprint $table): void {
            $table->string('current_stage_name')->nullable()->after('record_type');
            $table->boolean('is_valid')->default(true)->index()->after('current_stage_name');
            $table->timestamp('validity_checked_at')->nullable()->after('is_valid');
            $table->timestamp('invalidated_at')->nullable()->after('validity_checked_at');
            $table->string('invalid_reason')->nullable()->index()->after('invalidated_at');
        });

        Schema::table('salesforce_vehicles', function (Blueprint $table): void {
            $table->decimal('normal_sale_price', 14, 2)->nullable()->after('sale_price');
            $table->boolean('only_financed')->default(false)->after('financed_sale_price');
        });

        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            $table->decimal('financed_vehicle_sale_price', 14, 2)->nullable()->after('vehicle_sale_price');
            $table->boolean('vehicle_only_financed')->default(false)->after('financed_vehicle_sale_price');
        });

        DB::table('salesforce_vehicles')->update([
            'normal_sale_price' => DB::raw('sale_price'),
        ]);
    }

    public function down(): void
    {
        Schema::table('salesforce_sale_snapshots', function (Blueprint $table): void {
            $table->dropIndex(['is_valid']);
            $table->dropIndex(['invalid_reason']);
            $table->dropColumn([
                'current_stage_name',
                'is_valid',
                'validity_checked_at',
                'invalidated_at',
                'invalid_reason',
            ]);
        });

        Schema::table('salesforce_vehicles', function (Blueprint $table): void {
            $table->dropColumn(['normal_sale_price', 'only_financed']);
        });

        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            $table->dropColumn(['financed_vehicle_sale_price', 'vehicle_only_financed']);
        });
    }
};
