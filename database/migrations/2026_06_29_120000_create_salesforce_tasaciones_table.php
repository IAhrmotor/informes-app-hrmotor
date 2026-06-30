<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_tasaciones', function (Blueprint $table) {
            $table->id();
            $table->string('salesforce_id')->unique();
            $table->string('name')->nullable();
            $table->dateTime('created_date')->nullable()->index('sf_tasaciones_created_idx');
            $table->string('opportunity_salesforce_id')->nullable()->index('sf_tasaciones_opportunity_idx');
            $table->string('opportunity_name')->nullable();
            $table->date('contract_signed_date')->nullable()->index('sf_tasaciones_contract_idx');
            $table->boolean('cv_signed')->default(false)->index('sf_tasaciones_cv_signed_idx');
            $table->string('tracking_name')->nullable()->index('sf_tasaciones_tracking_idx');
            $table->string('negotiation_1')->nullable();
            $table->string('negotiation_2')->nullable();
            $table->string('negotiation_3')->nullable();
            $table->string('negotiation_4')->nullable();
            $table->string('source_query_profile')->nullable()->index('sf_tasaciones_profile_idx');
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_tasaciones');
    }
};
