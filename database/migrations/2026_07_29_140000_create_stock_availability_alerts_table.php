<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_availability_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_delegation_id')->constrained('stock_delegations')->cascadeOnDelete();
            $table->string('state')->default('open')->index();
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('salesforce_task_id')->nullable();
            $table->timestamp('task_created_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['stock_delegation_id', 'state'], 'stock_alert_delegation_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_availability_alerts');
    }
};
