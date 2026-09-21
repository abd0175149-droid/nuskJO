<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * نموذج الإدخال يتغيّر بتغيّر تصنيف العرض.
 *
 * الأثر البنيوي: في عمرة «مكة والمدينة» يضمّ الخيار الواحد فندقين بسعر واحد
 * لهما معاً — فالوحدة المسعّرة صارت «خيار الباقة» لا «الفندق». لذلك:
 *
 *   offer_hotels  →  offer_options   (تحمل الأسعار وما يشمله السعر)
 *   offer_stays   →  جديد: إقامة = فندق في مدينة، تحت الخيار (واحدة أو أكثر)
 *
 * والتأشيرات لا فنادق لها أصلاً، فحقولها تعيش على العرض بسعر واحد للفرد.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1) الخيارات المسعّرة (كانت offer_hotels) ──────────
        if (Schema::hasTable('offer_hotels') && !Schema::hasTable('offer_options')) {
            Schema::rename('offer_hotels', 'offer_options');
        }

        // ── 2) الإقامات: فندق في مدينة تحت خيار ───────────────
        if (!Schema::hasTable('offer_stays')) {
            Schema::create('offer_stays', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('option_id')->index();
                $t->string('city', 60)->nullable();          // مكة / المدينة / العقبة…
                $t->string('name', 150);
                $t->unsignedTinyInteger('rating')->nullable();
                $t->boolean('rating_plus')->default(false);
                $t->string('location', 80)->nullable();
                $t->string('meals', 60)->nullable();
                $t->string('distance_haram', 40)->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
            });
        }

        // ── 3) ترحيل كل فندق قائم إلى إقامة تحت خياره ────────
        if (Schema::hasTable('offer_options') && Schema::hasColumn('offer_options', 'name')) {
            $rows = DB::table('offer_options')->get();

            foreach ($rows as $row) {
                if (DB::table('offer_stays')->where('option_id', $row->id)->exists()) {
                    continue; // مُرحّل سابقاً
                }

                DB::table('offer_stays')->insert([
                    'option_id' => $row->id,
                    'city' => null,
                    'name' => $row->name ?: '—',
                    'rating' => $row->rating ?? null,
                    'rating_plus' => $row->rating_plus ?? false,
                    'location' => $row->location ?? null,
                    'meals' => $row->meals ?? null,
                    'distance_haram' => $row->distance_haram ?? null,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // أعمدة الفندق انتقلت للإقامة
            foreach (['name', 'rating', 'rating_plus', 'location', 'meals', 'distance_haram'] as $col) {
                if (Schema::hasColumn('offer_options', $col)) {
                    Schema::table('offer_options', function (Blueprint $t) use ($col) {
                        $t->dropColumn($col);
                    });
                }
            }
        }

        // ── 4) حقول تخصّ التصنيف على العرض ───────────────────
        Schema::table('offers', function (Blueprint $t) {
            // مسار العمرة: makkah_only | makkah_madinah
            if (!Schema::hasColumn('offers', 'route_mode')) {
                $t->string('route_mode', 20)->nullable();
            }
            // التأشيرات
            if (!Schema::hasColumn('offers', 'requirements')) {
                $t->text('requirements')->nullable();            // الشروط والمتطلبات
            }
            if (!Schema::hasColumn('offers', 'visa_validity')) {
                $t->string('visa_validity', 60)->nullable();     // صلاحية التأشيرة
            }
            if (!Schema::hasColumn('offers', 'visa_entries')) {
                $t->string('visa_entries', 40)->nullable();      // عدد مرات الدخول
            }
            if (!Schema::hasColumn('offers', 'visa_processing')) {
                $t->string('visa_processing', 60)->nullable();   // مدة الإصدار
            }
            if (!Schema::hasColumn('offers', 'price_per_person')) {
                $t->decimal('price_per_person', 12, 3)->nullable(); // سعر التأشيرة للفرد
            }
        });

        // العروض القائمة من نوع عمرة مسارها «مكة فقط»
        DB::table('offers')->where('category', 'umrah')->whereNull('route_mode')
            ->update(['route_mode' => 'makkah_only']);
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->dropColumn(['route_mode', 'requirements', 'visa_validity',
                            'visa_entries', 'visa_processing', 'price_per_person']);
        });

        Schema::table('offer_options', function (Blueprint $t) {
            $t->string('name', 150)->nullable();
            $t->unsignedTinyInteger('rating')->nullable();
            $t->boolean('rating_plus')->default(false);
            $t->string('location', 80)->nullable();
            $t->string('meals', 60)->nullable();
            $t->string('distance_haram', 40)->nullable();
        });

        // نُعيد أول إقامة إلى صفّ الخيار
        foreach (DB::table('offer_stays')->orderBy('sort_order')->get()->groupBy('option_id') as $optionId => $stays) {
            $s = $stays->first();
            DB::table('offer_options')->where('id', $optionId)->update([
                'name' => $s->name,
                'rating' => $s->rating,
                'rating_plus' => $s->rating_plus,
                'location' => $s->location,
                'meals' => $s->meals,
                'distance_haram' => $s->distance_haram,
            ]);
        }

        Schema::dropIfExists('offer_stays');

        if (Schema::hasTable('offer_options') && !Schema::hasTable('offer_hotels')) {
            Schema::rename('offer_options', 'offer_hotels');
        }
    }
};
