<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_executive_email_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('module_key', 64)->unique('seo_exec_email_settings_module_uq');
            $table->json('recipients');
            $table->unsignedBigInteger('updated_by_report_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('seo_executive_daily_reports', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date')->unique('seo_exec_daily_reports_date_uq');
            $table->dateTime('generated_at');
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->timestamps();
        });

        Schema::create('seo_executive_email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seo_executive_daily_report_id');
            $table->date('report_date');
            $table->string('recipient_email', 255);
            $table->char('recipient_hash', 64);
            $table->string('status', 32);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->string('error_message', 2000)->nullable();
            $table->timestamps();

            $table->unique(['report_date', 'recipient_hash'], 'seo_exec_delivery_recipient_date_uq');
            $table->index(['report_date', 'status'], 'seo_exec_delivery_date_status_idx');
            $table->index(['recipient_hash', 'report_date'], 'seo_exec_delivery_hash_date_idx');
            $table->foreign('seo_executive_daily_report_id', 'seo_exec_delivery_report_fk')
                ->references('id')->on('seo_executive_daily_reports')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_executive_email_deliveries');
        Schema::dropIfExists('seo_executive_daily_reports');
        Schema::dropIfExists('seo_executive_email_settings');
    }
};
