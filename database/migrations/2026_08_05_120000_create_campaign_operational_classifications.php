<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_operational_classifications', function (Blueprint $table): void {
            $table->id();
            $table->string('platform');
            $table->string('account_id')->default('');
            $table->string('campaign_id');
            $table->enum('classification', ['real', 'test', 'pending_review'])->default('pending_review');
            $table->text('reason');
            $table->foreignId('classified_by')->nullable()->constrained('report_users')->nullOnDelete();
            $table->timestamp('classified_at');
            $table->timestamps();
            $table->unique(['platform', 'account_id', 'campaign_id'], 'campaign_operational_class_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_operational_classifications');
    }
};
