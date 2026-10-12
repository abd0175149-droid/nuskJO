<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * نقل تكاليف العروض إلى الفاتورة: العرض يحمل البيع وحده، والوكيل والتكلفة يُدخلان في كل فاتورة.
 *
 * لا يُحذف عمود ولا صف: تكاليف العروض القديمة (offer_costs، وعقد الفندق في offer_stays) تبقى في القاعدة بلا استخدام.
 * حساب إقفال العروض صار «مصاريف الرحلات المشتركة»: الإقفال يسجّل مصاريف الرحلة مصروفاً، لا تخفيضاً لربح العرض.
 */
return new class extends Migration
{
    private const OLD = 'تكاليف عروض غير مستردة';
    private const NEW = 'مصاريف الرحلات المشتركة';
    private const DESC = 'مصاريف الرحلة المشتركة (باص مستأجر، مرشد، أسرّة لم تُبع) وفروق فواتير الموردين عند إقفال العروض';

    public function up(): void
    {
        if (DB::table('accounts')->where('name', self::NEW)->exists()) {
            return;
        }

        $old = DB::table('accounts')->where('name', self::OLD)->first();
        if ($old) {
            DB::table('accounts')->where('id', $old->id)->update(['name' => self::NEW, 'description' => self::DESC, 'updated_at' => now()]);

            return;
        }

        // 5500 محجوز للعمولات البنكية
        $parent = DB::table('accounts')->where('code', '5000')->first();
        if (!$parent) {
            return;
        }
        $code = collect(['5600', '5610', '5650', '5700', '5800', '5900'])
            ->first(fn ($c) => !DB::table('accounts')->where('code', $c)->exists());
        DB::table('accounts')->insert([
            'code' => $code,
            'name' => self::NEW,
            'parent_id' => $parent->id,
            'type' => 'expense',
            'is_system' => 1,
            'is_active' => 1,
            'currency' => 'JOD',
            'balance' => 0,
            'description' => self::DESC,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('accounts')->where('name', self::NEW)->update(['name' => self::OLD, 'updated_at' => now()]);
    }
};
