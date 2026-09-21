<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * إعادة هيكلة العروض: السعر ينتقل من العرض إلى الفندق.
 *
 * العرض الواحد قد يضمّ عدّة فنادق، ولكل فندق قائمة أسعار «للفرد» حسب سعة الغرفة
 * (مفردة/ثنائية/ثلاثية/رباعية) + بيان ما يشمله سعر ذلك الفندق (وجبات…).
 * «يشمل / لا يشمل» تبقى على مستوى العرض كاملاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── فنادق العرض وأسعارها ──────────────────────────────
        if (!Schema::hasTable('offer_hotels')) {
            Schema::create('offer_hotels', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('offer_id')->index();
                $t->string('name', 150);
                $t->unsignedTinyInteger('rating')->nullable();     // تصنيف الفندق 1..7
                $t->text('includes_note')->nullable();             // ما يشمله سعر هذا الفندق
                // {"single":120,"double":90,"triple":80,"quad":70} — للفرد بالدينار، null = غير متاح
                $t->json('prices')->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
            });
        }

        // ── ترحيل العروض القائمة إلى فندق واحد قبل حذف الأعمدة ──
        // العرض القديم كان يحمل سعراً واحداً للفرد بلا تفريق بين سعات الغرف،
        // فلا يصحّ إسناده لسعة معيّنة تلقائياً. نُرحّل الفندق وتصنيفه فقط،
        // ونترك الأسعار فارغة ليُدخلها المالك حسب السعة من الواجهة.
        // الأسعار القديمة تُسجّل في اللوج قبل حذف العمود للرجوع إليها.
        if (Schema::hasTable('offer_hotels') && Schema::hasColumn('offers', 'hotel_name')) {
            $cols = ['id', 'title', 'hotel_name'];
            if (Schema::hasColumn('offers', 'hotel_rating')) $cols[] = 'hotel_rating';
            if (Schema::hasColumn('offers', 'price_jod')) $cols[] = 'price_jod';

            $rows = DB::table('offers')->select($cols)->get();

            foreach ($rows as $row) {
                if (DB::table('offer_hotels')->where('offer_id', $row->id)->exists()) {
                    continue; // مُرحّل سابقاً
                }
                if (blank($row->hotel_name ?? null)) {
                    continue; // لا فندق يُرحّل
                }

                DB::table('offer_hotels')->insert([
                    'offer_id' => $row->id,
                    'name' => $row->hotel_name,
                    'rating' => $row->hotel_rating ?? null,
                    'includes_note' => null,
                    'prices' => json_encode(['single' => null, 'double' => null, 'triple' => null, 'quad' => null]),
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                Log::warning('ترحيل عرض إلى نموذج الفنادق — يلزم إدخال أسعار الغرف يدوياً', [
                    'offer_id' => $row->id,
                    'title' => $row->title,
                    'hotel' => $row->hotel_name,
                    'old_price_per_person_jod' => $row->price_jod ?? null,
                ]);
            }
        }

        // ── تنظيف أعمدة العرض ────────────────────────────────
        $drop = [
            'price_jod', 'price_per',          // السعر انتقل للفندق
            'departure_date', 'return_date',   // محذوفة بطلب المالك
            'available_seats', 'agent_id',
            'hotel_name', 'hotel_rating',      // انتقلا لمستوى الفندق
            'cost_jod', 'notes_internal',      // القسم الداخلي محذوف بالكامل
        ];

        foreach ($drop as $col) {
            if (Schema::hasColumn('offers', $col)) {
                Schema::table('offers', function (Blueprint $t) use ($col) {
                    $t->dropColumn($col);
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_hotels');

        Schema::table('offers', function (Blueprint $t) {
            if (!Schema::hasColumn('offers', 'price_jod')) $t->decimal('price_jod', 12, 3)->default(0);
            if (!Schema::hasColumn('offers', 'price_per')) $t->string('price_per', 16)->default('person');
            if (!Schema::hasColumn('offers', 'departure_date')) $t->date('departure_date')->nullable();
            if (!Schema::hasColumn('offers', 'return_date')) $t->date('return_date')->nullable();
            if (!Schema::hasColumn('offers', 'available_seats')) $t->unsignedInteger('available_seats')->nullable();
            if (!Schema::hasColumn('offers', 'agent_id')) $t->unsignedBigInteger('agent_id')->nullable();
            if (!Schema::hasColumn('offers', 'hotel_name')) $t->string('hotel_name', 120)->nullable();
            if (!Schema::hasColumn('offers', 'hotel_rating')) $t->unsignedTinyInteger('hotel_rating')->nullable();
            if (!Schema::hasColumn('offers', 'cost_jod')) $t->decimal('cost_jod', 12, 3)->nullable();
            if (!Schema::hasColumn('offers', 'notes_internal')) $t->text('notes_internal')->nullable();
        });
    }
};
