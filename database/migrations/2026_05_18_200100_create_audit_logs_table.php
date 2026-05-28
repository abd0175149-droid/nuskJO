<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 100)->nullable();
            $table->string('action', 30); // create, update, approve, reject, delete
            $table->string('entity_type', 50); // transfer, receipt, invoice, violation, expense, agent, client
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_label', 100)->nullable(); // e.g. TRF-20260518-0001
            $table->json('changes')->nullable(); // before/after diff
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
