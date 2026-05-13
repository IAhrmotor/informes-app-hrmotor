<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_form_sender_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('portal_original');
            $table->string('portal_value')->nullable();
            $table->string('sender_email');
            $table->string('receiver_account')->nullable();
            $table->string('type')->nullable();
            $table->string('delegation_name')->nullable();
            $table->string('commercial_group')->nullable();
            $table->string('status')->default('active');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index(['portal_original', 'sender_email'], 'form_sender_portal_email_idx');
            $table->index(['portal_original', 'portal_value'], 'form_sender_portal_value_idx');
            $table->index('commercial_group', 'form_sender_group_idx');
            $table->unique(['portal_original', 'sender_email', 'portal_value'], 'form_sender_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_form_sender_mappings');
    }
};
