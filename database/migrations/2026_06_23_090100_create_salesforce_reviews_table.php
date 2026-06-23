<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('salesforce_id')->unique();
            $table->dateTime('created_date')->nullable()->index('sf_reviews_created_idx');
            $table->string('owner_id')->nullable()->index('sf_reviews_owner_idx');
            $table->string('owner_name')->nullable();
            $table->string('opportunity_salesforce_id')->nullable()->index('sf_reviews_opportunity_idx');
            $table->string('opportunity_name')->nullable();
            $table->string('opportunity_owner_id')->nullable()->index('sf_reviews_opp_owner_idx');
            $table->string('opportunity_owner_name')->nullable();
            $table->string('opportunity_record_type_name')->nullable()->index('sf_reviews_opp_record_type_idx');
            $table->date('opportunity_cv_signed_date')->nullable()->index('sf_reviews_opp_cv_signed_idx');
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_reviews');
    }
};
