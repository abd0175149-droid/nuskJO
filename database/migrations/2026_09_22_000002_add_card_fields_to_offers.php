<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حقول بطاقة العرض التسويقية.
 *
 * البطاقة المرجعية للشركة تعرض لكل فندق: الموقع والتصنيف والوجبات والمسافة
 * عن الحرم إضافةً لأسعار الغرف، وتعرض على مستوى العرض ملاحظات عامة يراها
 * العميل. والتصنيف يُكتب «3+» فنحفظ الرقم للفرز ونضيف علامة الزائد منفصلة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_hotels', function (Blueprint $t) {
            if (!Schema::hasColumn('offer_hotels', 'location')) {
                $t->string('location', 80)->nullable()->after('rating');        // التيسير، جبل الكعبة…
            }
            if (!Schema::hasColumn('offer_hotels', 'meals')) {
                $t->string('meals', 60)->nullable()->after('location');          // بدون، إفطار، إفطار وعشاء
            }
            if (!Schema::hasColumn('offer_hotels', 'distance_haram')) {
                $t->string('distance_haram', 40)->nullable()->after('meals');    // «950 متر» أو «بلاط حرم»
            }
            if (!Schema::hasColumn('offer_hotels', 'rating_plus')) {
                $t->boolean('rating_plus')->default(false)->after('rating');     // 4 + زائد = «4+»
            }
        });

        Schema::table('offers', function (Blueprint $t) {
            if (!Schema::hasColumn('offers', 'notes_public')) {
                $t->text('notes_public')->nullable();          // ملاحظات يراها العميل (جواز ساري…)
            }
            if (!Schema::hasColumn('offers', 'hero_path')) {
                $t->string('hero_path', 180)->nullable();      // ترويسة خاصة بهذا العرض (اختيارية)
            }
            if (!Schema::hasColumn('offers', 'card_path')) {
                $t->string('card_path', 180)->nullable();      // البطاقة المولّدة
            }
            if (!Schema::hasColumn('offers', 'card_generated_at')) {
                $t->timestamp('card_generated_at')->nullable();
            }
            if (!Schema::hasColumn('offers', 'custom_card_path')) {
                $t->string('custom_card_path', 180)->nullable(); // بطاقة مرفوعة تتجاوز المولّدة
            }
        });
    }

    public function down(): void
    {
        Schema::table('offer_hotels', function (Blueprint $t) {
            $t->dropColumn(['location', 'meals', 'distance_haram', 'rating_plus']);
        });

        Schema::table('offers', function (Blueprint $t) {
            $t->dropColumn(['notes_public', 'hero_path', 'card_path', 'card_generated_at', 'custom_card_path']);
        });
    }
};
