<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_delegations', function (Blueprint $table): void {
            $table->id();
            $table->string('salesforce_id')->nullable()->unique();
            $table->string('salesforce_name')->nullable();
            $table->string('canonical_name');
            $table->string('normalized_key')->unique();
            $table->string('commercial_group')->nullable();
            $table->string('zone')->nullable();
            $table->unsignedInteger('capacity_total')->nullable();
            $table->string('capacity_source_name')->nullable();
            $table->timestamp('capacity_updated_at')->nullable();
            $table->boolean('is_commercial')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('salesforce_vehicles', function (Blueprint $table): void {
            $table->id();
            $table->string('salesforce_id')->unique();
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->string('plate')->nullable()->index();
            $table->string('brand')->nullable()->index();
            $table->string('model')->nullable()->index();
            $table->string('version')->nullable();
            $table->string('segment')->nullable()->index();
            $table->string('fuel')->nullable()->index();
            $table->string('body')->nullable()->index();
            $table->unsignedInteger('mileage')->nullable();
            $table->string('state')->nullable()->index();
            $table->foreignId('stock_delegation_id')->nullable()->constrained('stock_delegations')->nullOnDelete();
            $table->string('salesforce_delegation_id')->nullable()->index();
            $table->string('salesforce_delegation_name')->nullable()->index();
            $table->decimal('purchase_price', 14, 2)->nullable();
            $table->decimal('sale_price', 14, 2)->nullable();
            $table->decimal('financed_sale_price', 14, 2)->nullable();
            $table->date('entry_date')->nullable()->index();
            $table->string('buyer_id')->nullable();
            $table->string('buyer_name')->nullable()->index();
            $table->string('purchase_source')->nullable()->index();
            $table->boolean('is_in_stock')->default(true)->index();
            $table->timestamp('last_seen_stock_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['is_in_stock', 'state', 'stock_delegation_id'], 'sf_vehicles_stock_state_delegation_idx');
        });

        Schema::create('stock_daily_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('snapshot_date')->index();
            $table->foreignId('salesforce_vehicle_id')->constrained('salesforce_vehicles')->cascadeOnDelete();
            $table->string('vehicle_salesforce_id');
            $table->string('state')->index();
            $table->foreignId('stock_delegation_id')->nullable()->constrained('stock_delegations')->nullOnDelete();
            $table->string('delegation_name')->nullable()->index();
            $table->string('brand')->nullable()->index();
            $table->string('model')->nullable()->index();
            $table->string('segment')->nullable()->index();
            $table->string('fuel')->nullable()->index();
            $table->string('price_band')->nullable()->index();
            $table->decimal('purchase_price', 14, 2)->nullable();
            $table->decimal('sale_price', 14, 2)->nullable();
            $table->date('entry_date')->nullable();
            $table->unsignedInteger('days_in_stock')->nullable();
            $table->timestamps();

            $table->unique(['snapshot_date', 'salesforce_vehicle_id'], 'stock_daily_snapshot_vehicle_unique');
        });

        Schema::create('salesforce_sale_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('opportunity_salesforce_id')->unique();
            $table->string('opportunity_name')->nullable();
            $table->string('record_type')->nullable()->index();
            $table->date('signed_date')->nullable()->index();
            $table->string('delivery_store')->nullable()->index();
            $table->foreignId('stock_delegation_id')->nullable()->constrained('stock_delegations')->nullOnDelete();
            $table->string('vehicle_salesforce_id')->nullable()->index();
            $table->string('vehicle_plate')->nullable()->index();
            $table->date('vehicle_entry_date')->nullable();
            $table->unsignedInteger('rotation_days')->nullable();
            $table->decimal('sale_price', 14, 2)->nullable();
            $table->decimal('purchase_price', 14, 2)->nullable();
            $table->string('trade_in_vehicle_salesforce_id')->nullable()->index();
            $table->string('trade_in_vehicle_plate')->nullable();
            $table->decimal('trade_in_amount', 14, 2)->nullable();
            $table->boolean('sale_management')->nullable();
            $table->decimal('management_cost', 14, 2)->nullable();
            $table->decimal('logistics_cost', 14, 2)->nullable();
            $table->decimal('transfer_cost', 14, 2)->nullable();
            $table->decimal('warranty_amount', 14, 2)->nullable();
            $table->decimal('plan_auto_plus_amount', 14, 2)->nullable();
            $table->decimal('cae_amount', 14, 2)->nullable();
            $table->decimal('discount_amount', 14, 2)->nullable();
            $table->decimal('financial_discount_amount', 14, 2)->nullable();
            $table->decimal('logistics_discount_amount', 14, 2)->nullable();
            $table->decimal('total_amount', 14, 2)->nullable();
            $table->json('quality_issues')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();
        });

        Schema::create('salesforce_logistics', function (Blueprint $table): void {
            $table->id();
            $table->string('salesforce_id')->unique();
            $table->string('name')->nullable();
            $table->string('vehicle_salesforce_id')->nullable()->index();
            $table->string('vehicle_name')->nullable();
            $table->string('origin_delegation_salesforce_id')->nullable()->index();
            $table->string('origin_delegation_name')->nullable()->index();
            $table->string('destination_delegation_salesforce_id')->nullable()->index();
            $table->string('destination_delegation_name')->nullable()->index();
            $table->string('state')->nullable()->index();
            $table->date('transport_date')->nullable()->index();
            $table->date('reception_date')->nullable();
            $table->date('destination_date')->nullable();
            $table->dateTime('salesforce_last_modified_at')->nullable()->index();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });

        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            $table->decimal('contract_vehicle_sale_amount', 14, 2)->nullable()->after('vehicle_sale_price');
            $table->decimal('trade_in_amount', 14, 2)->nullable()->after('appraised_vehicle_plate');
            $table->decimal('management_cost', 14, 2)->nullable()->after('gestion_de_venta');
            $table->decimal('logistics_cost', 14, 2)->nullable()->after('management_cost');
            $table->decimal('transfer_cost', 14, 2)->nullable()->after('logistics_cost');
            $table->decimal('logistics_discount', 14, 2)->nullable()->after('transfer_cost');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            $table->dropColumn([
                'contract_vehicle_sale_amount',
                'trade_in_amount',
                'management_cost',
                'logistics_cost',
                'transfer_cost',
                'logistics_discount',
            ]);
        });

        Schema::dropIfExists('salesforce_logistics');
        Schema::dropIfExists('salesforce_sale_snapshots');
        Schema::dropIfExists('stock_daily_snapshots');
        Schema::dropIfExists('salesforce_vehicles');
        Schema::dropIfExists('stock_delegations');
    }
};
