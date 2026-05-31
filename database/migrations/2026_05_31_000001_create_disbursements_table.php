<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursements', function (Blueprint $table) {
            $table->id();
            $table->string('disbursement_number', 30)->unique();
            $table->foreignId('account_id')->constrained('accounts'); // الحساب المدين
            $table->foreignId('agent_id')->nullable()->constrained('agents'); // وكيل (اختياري)
            $table->decimal('amount', 15, 3); // المبلغ بالعملة المختارة
            $table->string('currency', 3)->default('JOD');
            $table->decimal('amount_sar', 15, 2)->nullable(); // المبلغ بالريال (للوكلاء)
            $table->decimal('exchange_rate', 10, 6)->default(0.190000);
            $table->string('payment_method', 30); // cash, bank, check
            $table->string('reference_number', 100)->nullable();
            $table->date('disbursement_date');
            $table->text('description');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('modified_by')->nullable()->constrained('users');
            $table->timestamp('modified_at')->nullable();
            $table->json('original_values')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('disbursement_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursements');
    }
};
