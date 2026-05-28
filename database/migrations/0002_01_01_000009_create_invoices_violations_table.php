<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 30)->unique();
            $table->foreignId('agent_id')->constrained('agents');
            $table->foreignId('client_id')->constrained('clients');
            $table->decimal('exchange_rate_snapshot', 10, 6);
            $table->decimal('subtotal_sar', 15, 2)->default(0.00);
            $table->decimal('discount_sar', 15, 2)->default(0.00);
            $table->decimal('total_sar', 15, 2)->default(0.00);
            $table->decimal('total_jod', 15, 3)->default(0.000);
            $table->decimal('services_cost_sar', 15, 2)->default(0.00);
            $table->decimal('violations_cost_sar', 15, 2)->default(0.00);
            $table->decimal('profit_sar', 15, 2)->default(0.00);
            $table->decimal('profit_jod', 15, 3)->default(0.000);
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('invoice_date');
        });

        Schema::create('violations', function (Blueprint $table) {
            $table->id();
            $table->string('violation_number', 30)->unique();
            $table->foreignId('agent_id')->constrained('agents');
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('violation_type_id')->constrained('violation_types');
            $table->string('passport_number', 30)->nullable();
            $table->string('passport_name', 150)->nullable();
            $table->decimal('cost_sar', 15, 2);
            $table->date('violation_date');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->string('billing_status', 20)->default('unbilled');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('billing_status');
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('item_type', 20);
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->unsignedBigInteger('violation_id')->nullable();
            $table->foreign('violation_id')->references('id')->on('violations')->nullOnDelete();
            $table->string('description', 255);
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price_sar', 15, 2);
            $table->decimal('sell_price_jod', 15, 3);
            $table->decimal('total_cost_sar', 15, 2);
            $table->decimal('total_sell_jod', 15, 3);
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('violations');
        Schema::dropIfExists('invoices');
    }
};
