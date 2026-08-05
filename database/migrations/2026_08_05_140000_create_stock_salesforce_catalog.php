<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_catalog_values', function (Blueprint $table): void {
            $table->id();
            $table->string('object_api_name');
            $table->string('field_api_name');
            $table->string('api_value');
            $table->string('label');
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique(['object_api_name', 'field_api_name', 'api_value'], 'stock_catalog_value_unique');
        });
        Schema::create('stock_catalog_aliases', function (Blueprint $table): void {
            $table->id();
            $table->string('field_api_name');
            $table->string('raw_value');
            $table->string('normalized_key');
            $table->foreignId('stock_catalog_value_id')->constrained('stock_catalog_values')->cascadeOnDelete();
            $table->string('rule_name');
            $table->text('reason');
            $table->timestamps();
            $table->unique(['field_api_name', 'normalized_key'], 'stock_catalog_alias_unique');
        });
        Schema::table('salesforce_vehicles', function (Blueprint $table): void {
            $table->json('catalog_normalization')->nullable()->after('raw_payload');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_vehicles', fn (Blueprint $table) => $table->dropColumn('catalog_normalization'));
        Schema::dropIfExists('stock_catalog_aliases');
        Schema::dropIfExists('stock_catalog_values');
    }
};
