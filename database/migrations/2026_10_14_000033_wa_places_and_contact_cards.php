<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المواقع وبطاقات الاتصال المعتمدة (E33): مصدر الدبوس والبطاقة الوحيد، يديره المدير بصلاحية whatsapp.settings.
 * - wa_places: المكتب ونقاط تجمع الفوج والفنادق. مكتب افتراضي واحد للبوت يُفرض في الكود لا بفهرس جزئي.
 * - wa_contact_cards: بطاقات عامة بموافقة مكتوبة من صاحب كل رقم.
 * لا بيانات عملاء فيهما. جداول جديدة فقط ومتكررة الأمان: لا تمس أي جدول قائم (ولا wa_messages وفهرس بحثها).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wa_places')) {
            Schema::create('wa_places', function (Blueprint $t) {
                $t->id();
                // ما يظهر للعميل في الدبوس
                $t->string('name', 60);
                $t->string('address', 160)->nullable();
                $t->decimal('latitude', 10, 7);
                $t->decimal('longitude', 10, 7);
                // office | meeting | hotel | other
                $t->string('kind', 16)->default('office');
                $t->boolean('is_default')->default(false);
                $t->boolean('bot_allowed')->default(false);
                $t->boolean('is_active')->default(true);
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->index(['is_active', 'kind']);
            });
        }

        if (!Schema::hasTable('wa_contact_cards')) {
            Schema::create('wa_contact_cards', function (Blueprint $t) {
                $t->id();
                // تسمية داخلية («المندوب في جدة»)
                $t->string('label', 60);
                $t->string('formatted_name', 80);
                $t->string('org', 80)->nullable();
                $t->string('job_title', 60)->nullable();
                // +9627XXXXXXXX
                $t->string('phone_e164', 20);
                // أرقام فقط، ولرقم على واتساب وحده (يظهر للعميل زرّا Message وSave contact)
                $t->string('wa_id', 20)->nullable();
                $t->string('email', 120)->nullable();
                // https فقط
                $t->string('url', 200)->nullable();
                // صاحب الرقم إن كان موظفاً، ومن وافق ومتى وكيف
                $t->unsignedBigInteger('consent_user_id')->nullable();
                $t->string('consent_note', 200);
                $t->timestamp('consented_at')->nullable();
                $t->boolean('bot_allowed')->default(false);
                $t->boolean('is_active')->default(true);
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->index(['is_active']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_contact_cards');
        Schema::dropIfExists('wa_places');
    }
};
