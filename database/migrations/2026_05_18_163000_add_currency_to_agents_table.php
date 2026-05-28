<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('currency', 3)->default('SAR')->after('country');
        });

        // تحديث الوكلاء الحاليين: إذا الدولة أردن → JOD
        \DB::table('agents')->where('country', 'JO')->update(['currency' => 'JOD']);
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
