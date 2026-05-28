<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Account;

return new class extends Migration
{
    /**
     * نقل "دائنون متنوعون" (2101) ليكون ابن مباشر لـ "الالتزامات" (2000)
     * بدلاً من التزامات قصيرة الأجل (2100)
     */
    public function up(): void
    {
        $liabilities = Account::where('code', '2000')->first();
        $creditors = Account::where('code', '2101')->first();

        if ($liabilities && $creditors) {
            $creditors->update(['parent_id' => $liabilities->id]);
        }
    }

    public function down(): void
    {
        $shortTerm = Account::where('code', '2100')->first();
        $creditors = Account::where('code', '2101')->first();

        if ($shortTerm && $creditors) {
            $creditors->update(['parent_id' => $shortTerm->id]);
        }
    }
};
