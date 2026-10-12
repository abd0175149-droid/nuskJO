<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * العروض الجاهزة وتكاليف العروض — سجل عرض واحد للبوت والفاتورة والقيد.
 *
 * - العرض الجاهز علامة على العرض نفسه (is_ready + تاريخ ومقاعد)، لا جدول ثانٍ.
 * - offer_groups: كل قائمة منسدلة في فاتورة العرض (فندق مكة، النقل…).
 * - offer_options: أسعار البيع كما هي + فرق بيع/سعة/تعطيل للخيارات غير الفندقية.
 * - offer_stays: وصف الفندق كما هو + عقده (الوكيل وتكلفة الفرد لليلة لكل سعة).
 * - offer_costs: بنود التكلفة غير الفندقية بوكلائها (ثابتة أو على خيار).
 * - الفاتورة تُربط بالعرض ومقاعده ولقطة ما بيع، وبنودها تعرف مصدرها.
 *
 * العروض القائمة تُرحَّل بنمط «باقات جاهزة»: مجموعة «الإقامة» تضمّ خياراتها كما هي،
 * فلا تتغير حمولة البوت ولا البطاقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── العرض ────────────────────────────────────────────
        Schema::table('offers', function (Blueprint $t) {
            if (!Schema::hasColumn('offers', 'is_ready')) $t->boolean('is_ready')->default(false);
            if (!Schema::hasColumn('offers', 'trip_date')) $t->date('trip_date')->nullable();
            if (!Schema::hasColumn('offers', 'return_date')) $t->date('return_date')->nullable();
            if (!Schema::hasColumn('offers', 'travel_mode')) $t->string('travel_mode', 10)->nullable();      // land | air
            if (!Schema::hasColumn('offers', 'destination')) $t->string('destination', 120)->nullable();
            if (!Schema::hasColumn('offers', 'total_seats')) $t->unsignedInteger('total_seats')->nullable();
            // أول كتابة في معاملة فاتورة العرض — قفل كتابة على SQLite دون لمس updated_at
            if (!Schema::hasColumn('offers', 'seats_version')) $t->unsignedInteger('seats_version')->default(0);
            if (!Schema::hasColumn('offers', 'base_sell_pp')) $t->decimal('base_sell_pp', 12, 3)->default(0);
            if (!Schema::hasColumn('offers', 'pricing_mode')) $t->string('pricing_mode', 10)->default('bundle'); // city | bundle
            if (!Schema::hasColumn('offers', 'settlement_status')) $t->string('settlement_status', 20)->nullable();
        });

        // ── مجموعات الاختيار ─────────────────────────────────
        if (!Schema::hasTable('offer_groups')) {
            Schema::create('offer_groups', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('offer_id')->index();
                $t->string('kind', 12)->default('stay');          // stay | transport | flight | service
                $t->string('label', 120);
                $t->string('city', 60)->nullable();               // مجموعات الفنادق في نمط المدن
                $t->unsignedSmallInteger('nights')->nullable();
                $t->unsignedBigInteger('service_id')->nullable(); // خدمة الإيراد لفنادق المجموعة
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
            });
        }

        Schema::table('offer_options', function (Blueprint $t) {
            if (!Schema::hasColumn('offer_options', 'group_id')) $t->unsignedBigInteger('group_id')->nullable()->index();
            if (!Schema::hasColumn('offer_options', 'label')) $t->string('label', 120)->nullable();
            if (!Schema::hasColumn('offer_options', 'sell_flat')) $t->decimal('sell_flat', 12, 3)->default(0);
            if (!Schema::hasColumn('offer_options', 'capacity')) $t->unsignedInteger('capacity')->nullable();
            if (!Schema::hasColumn('offer_options', 'is_active')) $t->boolean('is_active')->default(true);
        });

        Schema::table('offer_stays', function (Blueprint $t) {
            if (!Schema::hasColumn('offer_stays', 'agent_id')) $t->unsignedBigInteger('agent_id')->nullable();
            // {"single":..,"double":..,"triple":..,"quad":..} تكلفة الفرد لليلة الواحدة حسب سعة الغرفة، بعملة الوكيل
            if (!Schema::hasColumn('offer_stays', 'room_rates')) $t->json('room_rates')->nullable();
            if (!Schema::hasColumn('offer_stays', 'nights')) $t->unsignedSmallInteger('nights')->nullable();
            if (!Schema::hasColumn('offer_stays', 'currency')) $t->string('currency', 3)->default('SAR');
        });

        if (!Schema::hasTable('offer_costs')) {
            Schema::create('offer_costs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('offer_id')->index();
                $t->unsignedBigInteger('option_id')->nullable()->index(); // فارغ = بند ثابت في كل فاتورة
                $t->string('kind', 12)->default('service');               // visa | flight | transport | service
                $t->string('label', 150);
                $t->unsignedBigInteger('agent_id')->nullable();
                $t->unsignedBigInteger('service_id')->nullable();
                $t->string('basis', 12)->default('per_person');           // per_person | per_group
                $t->decimal('qty', 10, 2)->default(1);
                $t->decimal('unit_cost', 12, 3)->default(0);
                $t->string('currency', 3)->default('JOD');
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
            });
        }

        // ── الفاتورة ─────────────────────────────────────────
        Schema::table('invoices', function (Blueprint $t) {
            if (!Schema::hasColumn('invoices', 'offer_id')) $t->unsignedBigInteger('offer_id')->nullable()->index();
            if (!Schema::hasColumn('invoices', 'offer_seats')) $t->unsignedInteger('offer_seats')->nullable();
            if (!Schema::hasColumn('invoices', 'offer_lines')) $t->json('offer_lines')->nullable();
            if (!Schema::hasColumn('invoices', 'quote_request_id')) $t->unsignedBigInteger('quote_request_id')->nullable();
        });

        Schema::table('invoice_items', function (Blueprint $t) {
            if (!Schema::hasColumn('invoice_items', 'source_type')) $t->string('source_type', 10)->nullable(); // stay | cost | manual
            if (!Schema::hasColumn('invoice_items', 'source_id')) $t->unsignedBigInteger('source_id')->nullable();
            if (!Schema::hasColumn('invoice_items', 'cost_currency')) $t->string('cost_currency', 3)->nullable();
            if (!Schema::hasColumn('invoice_items', 'cost_original')) $t->decimal('cost_original', 14, 3)->nullable();
            if (!Schema::hasColumn('invoice_items', 'fx_rate')) $t->decimal('fx_rate', 10, 6)->nullable();
        });

        Schema::table('quote_requests', function (Blueprint $t) {
            if (!Schema::hasColumn('quote_requests', 'offer_selection')) $t->json('offer_selection')->nullable();
            if (!Schema::hasColumn('quote_requests', 'invoice_id')) $t->unsignedBigInteger('invoice_id')->nullable();
        });

        // سند الصرف لوكيل يمكن ربطه بعرض ليظهر في «ما ندين به عن العرض»
        if (Schema::hasTable('disbursements')) {
            Schema::table('disbursements', function (Blueprint $t) {
                if (!Schema::hasColumn('disbursements', 'offer_id')) $t->unsignedBigInteger('offer_id')->nullable()->index();
            });
        }

        // ── إقفال العرض ──────────────────────────────────────
        if (!Schema::hasTable('offer_settlements')) {
            Schema::create('offer_settlements', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('offer_id')->unique();
                $t->json('lines')->nullable();
                $t->json('inputs')->nullable();                 // الأسرّة المحجوزة وفروق فواتير الموردين كما أُدخلت
                $t->decimal('total_jod', 14, 3)->default(0);
                $t->unsignedBigInteger('journal_entry_id')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->timestamps();
            });
        }

        // حساب «تكاليف عروض غير مستردة» تحت المصروفات — 5500 محجوز للعمولات البنكية
        $parent = DB::table('accounts')->where('code', '5000')->first();
        if ($parent && !DB::table('accounts')->where('name', 'تكاليف عروض غير مستردة')->exists()) {
            $code = collect(['5600', '5610', '5650', '5700', '5800', '5900'])
                ->first(fn ($c) => !DB::table('accounts')->where('code', $c)->exists());
            DB::table('accounts')->insert([
                'code' => $code,
                'name' => 'تكاليف عروض غير مستردة',
                'parent_id' => $parent->id,
                'type' => 'expense',
                'is_system' => 1,
                'is_active' => 1,
                'currency' => 'JOD',
                'balance' => 0,
                'description' => 'باقي البنود المقطوعة والأسرّة الشاغرة وفروق فواتير الموردين عند إقفال العروض',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // صلاحية رؤية تكاليف العروض ووكلائها — للمدير افتراضياً
        if (Schema::hasTable('permissions') && !DB::table('permissions')->where('slug', 'offers.view_cost')->exists()) {
            $permId = DB::table('permissions')->insertGetId([
                'slug' => 'offers.view_cost',
                'name' => 'عرض تكاليف العروض',
                'module' => 'offers',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $admin = DB::table('roles')->where('slug', 'admin')->first();
            if ($admin && Schema::hasTable('role_permissions')) {
                DB::table('role_permissions')->insert(['role_id' => $admin->id, 'permission_id' => $permId]);
            }
        }

        // ── ترحيل العروض القائمة: نمط «باقات جاهزة» ─────────
        foreach (DB::table('offers')->where('category', '!=', 'visa')->get(['id']) as $offer) {
            $options = DB::table('offer_options')->where('offer_id', $offer->id)->whereNull('group_id')->pluck('id');
            if ($options->isEmpty()) {
                continue;
            }
            $groupId = DB::table('offer_groups')->insertGetId([
                'offer_id' => $offer->id,
                'kind' => 'stay',
                'label' => 'الإقامة',
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('offer_options')->whereIn('id', $options)->update(['group_id' => $groupId]);
            DB::table('offers')->where('id', $offer->id)->update(['pricing_mode' => 'bundle']);
        }
    }

    public function down(): void
    {
        DB::table('offer_options')->update(['group_id' => null]);
        Schema::dropIfExists('offer_groups');
        Schema::dropIfExists('offer_costs');
        Schema::dropIfExists('offer_settlements');

        $drop = fn (string $table, array $cols) => Schema::table($table, function (Blueprint $t) use ($table, $cols) {
            foreach ($cols as $c) {
                if (Schema::hasColumn($table, $c)) $t->dropColumn($c);
            }
        });
        $drop('offers', ['is_ready', 'trip_date', 'return_date', 'travel_mode', 'destination', 'total_seats', 'seats_version', 'base_sell_pp', 'pricing_mode', 'settlement_status']);
        $drop('offer_options', ['label', 'sell_flat', 'capacity', 'is_active']);
        $drop('offer_stays', ['agent_id', 'room_rates', 'nights', 'currency']);
        $drop('invoices', ['offer_seats', 'offer_lines', 'quote_request_id']);
        $drop('invoice_items', ['source_type', 'source_id', 'cost_currency', 'cost_original', 'fx_rate']);
        $drop('quote_requests', ['offer_selection', 'invoice_id']);
        DB::table('permissions')->where('slug', 'offers.view_cost')->delete();
    }
};
