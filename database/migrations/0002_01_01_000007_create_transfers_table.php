<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number', 30)->unique();
            $table->foreignId('agent_id')->constrained('agents');
            $table->decimal('amount_sar', 15, 2);
            $table->decimal('cost_jod', 15, 3);
            $table->decimal('exchange_rate', 10, 6);
            $table->string('payment_method', 30);
            $table->string('reference_number', 100)->nullable();
            $table->date('transfer_date');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('transfer_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
