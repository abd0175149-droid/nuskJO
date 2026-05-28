<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // سجل الحركات المالية
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->timestamp('entry_date')->useCurrent();
            $table->string('entity_type', 20); // 'agent' or 'client'
            $table->unsignedBigInteger('entity_id');
            $table->string('transaction_type', 30); // 'transfer','violation','invoice','receipt','opening_balance','adjustment'
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->decimal('debit', 15, 3)->default(0.000);
            $table->decimal('credit', 15, 3)->default(0.000);
            $table->decimal('balance_after', 15, 3);
            $table->string('currency', 3); // 'SAR' or 'JOD'
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->index(['entity_type', 'entity_id']);
            $table->index('entry_date');
            $table->index(['transaction_type', 'transaction_id']);
        });

        // الإشعارات
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->string('type', 50);
            $table->string('icon', 50)->nullable();
            $table->string('action_url', 500)->nullable();
            $table->json('data')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'is_read']);
        });

        // سجل النشاطات
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('action', 50);
            $table->string('model_type', 50);
            $table->unsignedBigInteger('model_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['model_type', 'model_id']);
            $table->index('user_id');
        });

        // المرفقات
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type', 50);
            $table->unsignedBigInteger('attachable_id');
            $table->string('file_name', 255);
            $table->string('file_path', 500);
            $table->string('file_type', 50)->nullable();
            $table->integer('file_size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('ledger_entries');
    }
};
