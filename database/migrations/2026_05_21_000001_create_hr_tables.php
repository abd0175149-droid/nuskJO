<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ==========================================
        // 1. الأقسام الإدارية
        // ==========================================
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 20)->unique();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('manager_id')->references('id')->on('users')->nullOnDelete();
        });

        // ==========================================
        // 2. ورديات العمل (مرنة)
        // ==========================================
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 20)->unique();
            $table->time('start_time');
            $table->time('end_time');
            $table->integer('grace_minutes')->default(15);
            $table->json('working_days')->nullable(); // [0,1,2,3,4] أحد–خميس
            $table->string('country', 5)->default('ALL'); // SA, JO, ALL
            $table->integer('break_minutes')->default(60);
            $table->boolean('is_flexible')->default(false);
            $table->decimal('min_hours', 4, 2)->nullable(); // للوردية المرنة
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ==========================================
        // 3. الموظفين
        // ==========================================
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number', 30)->unique();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('job_title', 150)->nullable();
            $table->date('hire_date');
            $table->string('contract_type', 30)->default('full_time'); // full_time, part_time, contract
            $table->string('country', 5)->default('JO'); // SA, JO
            $table->string('currency', 3)->default('JOD'); // SAR, JOD

            // الراتب والبدلات
            $table->decimal('basic_salary', 15, 3)->default(0);
            $table->decimal('housing_allowance', 15, 3)->default(0);
            $table->decimal('transport_allowance', 15, 3)->default(0);
            $table->decimal('other_allowance', 15, 3)->default(0);

            // إعدادات العمل الإضافي (خياران لكل موظف)
            $table->string('overtime_calc_method', 20)->default('multiplier'); // fixed أو multiplier
            $table->decimal('overtime_hourly_rate', 15, 3)->default(0); // مبلغ ثابت/ساعة
            $table->decimal('overtime_multiplier', 4, 2)->default(1.50); // مُضاعف مثل 1.5x

            // فترة سماح مخصصة (NULL = استخدام إعداد الوردية)
            $table->integer('custom_grace_minutes')->nullable();

            // بيانات بنكية
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account', 50)->nullable(); // IBAN
            $table->string('bank_swift', 20)->nullable(); // SWIFT/BIC

            // بيانات شخصية
            $table->string('national_id', 30)->nullable();
            $table->string('passport_number', 30)->nullable();

            // ربط محاسبي
            $table->unsignedBigInteger('account_id')->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete();
        });

        // ==========================================
        // 4. تخصيص وردية يومية لموظف (overrides)
        // ==========================================
        Schema::create('employee_shift_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->tinyInteger('day_of_week'); // 0=أحد ... 6=سبت
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
            $table->unique(['employee_id', 'day_of_week']);
        });

        // ==========================================
        // 5. مواقع الحضور المعتمدة
        // ==========================================
        Schema::create('attendance_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 10); // ip, geo
            $table->string('ip_range', 100)->nullable(); // مثل 192.168.1.0/24
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->integer('radius_meters')->default(200);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ==========================================
        // 6. سجل الحضور والانصراف
        // ==========================================
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('date');
            $table->timestamp('check_in')->nullable();
            $table->timestamp('check_out')->nullable();

            // بيانات التحقق — الحضور
            $table->string('check_in_ip', 45)->nullable();
            $table->decimal('check_in_lat', 10, 7)->nullable();
            $table->decimal('check_in_lng', 10, 7)->nullable();
            $table->string('check_in_method', 20)->nullable(); // ip, geo, webauthn, manual

            // بيانات التحقق — الانصراف
            $table->string('check_out_ip', 45)->nullable();
            $table->decimal('check_out_lat', 10, 7)->nullable();
            $table->decimal('check_out_lng', 10, 7)->nullable();
            $table->string('check_out_method', 20)->nullable();

            // حسابات (يُحسب عند الانصراف عبر Observer)
            $table->decimal('worked_hours', 5, 2)->default(0);
            $table->integer('late_minutes')->default(0);
            $table->integer('overtime_minutes')->default(0);

            $table->string('status', 20)->default('present'); // present, absent, late, half_day, holiday
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->unique(['employee_id', 'date']);
            $table->index('date');
            $table->index('status');
        });

        // ==========================================
        // 7. أنواع الإجازات (مخصصة SA/JO)
        // ==========================================
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 20)->unique();
            $table->string('country', 5)->default('ALL'); // SA, JO, ALL
            $table->integer('default_days')->default(0);
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_attachment')->default(false);
            $table->integer('max_consecutive_days')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // ==========================================
        // 8. أرصدة الإجازات لكل موظف
        // ==========================================
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->integer('year');
            $table->integer('total_days')->default(0);
            $table->integer('used_days')->default(0);
            $table->integer('remaining_days')->default(0);
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->cascadeOnDelete();
            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });

        // ==========================================
        // 9. طلبات الإجازات (HasApproval)
        // ==========================================
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number', 30)->unique();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('days_count')->default(1);
            $table->boolean('is_paid')->default(true);
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending'); // pending, approved, rejected
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->index('status');
        });

        // ==========================================
        // 10. طلبات السلف (HasApproval)
        // ==========================================
        Schema::create('advances', function (Blueprint $table) {
            $table->id();
            $table->string('advance_number', 30)->unique();
            $table->unsignedBigInteger('employee_id');
            $table->decimal('amount', 15, 3);
            $table->string('currency', 3)->default('JOD');
            $table->integer('installments_count')->default(1);
            $table->decimal('installment_amount', 15, 3)->default(0);
            $table->decimal('remaining_amount', 15, 3)->default(0);
            $table->text('reason')->nullable();
            $table->string('payment_method', 30)->default('cash'); // cash, bank
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, paid, completed
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->index('status');
        });

        // ==========================================
        // 11. أقساط السلف
        // ==========================================
        Schema::create('advance_installments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('advance_id');
            $table->unsignedBigInteger('employee_id');
            $table->integer('month'); // 1-12
            $table->integer('year');
            $table->decimal('amount', 15, 3);
            $table->boolean('is_paid')->default(false);
            $table->timestamps();

            $table->foreign('advance_id')->references('id')->on('advances')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->index(['employee_id', 'month', 'year']);
        });

        // ==========================================
        // 12. المخالفات الداخلية
        // ==========================================
        Schema::create('employee_penalties', function (Blueprint $table) {
            $table->id();
            $table->string('penalty_number', 30)->unique();
            $table->unsignedBigInteger('employee_id');
            $table->string('penalty_type', 20)->default('warning'); // warning, deduction
            $table->integer('deduction_days')->default(0);
            $table->decimal('deduction_amount', 15, 3)->default(0);
            $table->date('penalty_date');
            $table->text('reason')->nullable();
            $table->boolean('is_deducted')->default(false); // هل تم خصمها في المسير؟
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['employee_id', 'is_deducted']);
        });

        // ==========================================
        // 13. مسيرات الرواتب (HasApproval)
        // ==========================================
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->string('payroll_number', 30)->unique();
            $table->integer('month'); // 1-12
            $table->integer('year');
            $table->string('currency', 3)->default('JOD');

            $table->decimal('total_basic', 15, 3)->default(0);
            $table->decimal('total_allowances', 15, 3)->default(0);
            $table->decimal('total_deductions', 15, 3)->default(0);
            $table->decimal('total_net', 15, 3)->default(0);
            $table->integer('employees_count')->default(0);

            $table->string('status', 20)->default('draft'); // draft, pending, approved, rejected
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['month', 'year', 'currency']);
            $table->index('status');
        });

        // ==========================================
        // 14. بنود مسير الرواتب
        // ==========================================
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payroll_id');
            $table->unsignedBigInteger('employee_id');

            // استحقاقات
            $table->decimal('basic_salary', 15, 3)->default(0);
            $table->decimal('housing_allowance', 15, 3)->default(0);
            $table->decimal('transport_allowance', 15, 3)->default(0);
            $table->decimal('other_allowance', 15, 3)->default(0);
            $table->decimal('overtime_amount', 15, 3)->default(0);
            $table->decimal('bonus', 15, 3)->default(0);

            // خصومات
            $table->decimal('late_deduction', 15, 3)->default(0);
            $table->decimal('absence_deduction', 15, 3)->default(0);
            $table->decimal('unpaid_leave_deduction', 15, 3)->default(0);
            $table->decimal('advance_deduction', 15, 3)->default(0);
            $table->decimal('penalty_deduction', 15, 3)->default(0);

            // إجماليات
            $table->decimal('total_earnings', 15, 3)->default(0);
            $table->decimal('total_deductions', 15, 3)->default(0);
            $table->decimal('net_salary', 15, 3)->default(0);
            $table->timestamps();

            $table->foreign('payroll_id')->references('id')->on('payrolls')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees');
            $table->unique(['payroll_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payrolls');
        Schema::dropIfExists('employee_penalties');
        Schema::dropIfExists('advance_installments');
        Schema::dropIfExists('advances');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('attendance_locations');
        Schema::dropIfExists('employee_shift_overrides');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('departments');
    }
};
