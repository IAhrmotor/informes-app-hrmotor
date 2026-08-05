<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('salesforce_calls', 'classified_at')) {
            Schema::table('salesforce_calls', function (Blueprint $table): void {
                $table->timestamp('classified_at')->nullable()->index('sf_calls_classified_at_idx')->after('classification_rule_version');
            });
        }

        if (! Schema::hasTable('salesforce_call_classification_history')) {
        Schema::create('salesforce_call_classification_history', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('salesforce_call_id');
            $table->foreign('salesforce_call_id', 'call_class_history_call_fk')->references('id')->on('salesforce_calls')->cascadeOnDelete();
            $table->string('task_salesforce_id')->index();
            $table->string('previous_rule_version')->nullable();
            $table->string('new_rule_version');
            $table->string('change_source');
            $table->text('reason');
            $table->json('raw_values');
            $table->json('previous_classification')->nullable();
            $table->json('new_classification');
            $table->timestamp('classified_at');
            $table->timestamps();
            $table->index(['task_salesforce_id', 'classified_at'], 'call_class_history_task_date_idx');
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_call_classification_history');
        Schema::table('salesforce_calls', function (Blueprint $table): void {
            $table->dropIndex('sf_calls_classified_at_idx');
            $table->dropColumn('classified_at');
        });
    }
};
