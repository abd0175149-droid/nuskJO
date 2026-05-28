<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('currency', 3)->default('JOD')->after('country');
            $table->string('contact_person', 150)->nullable()->after('notes');
        });

        \DB::table('clients')->where('country', 'SA')->update(['currency' => 'SAR']);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['currency', 'contact_person']);
        });
    }
};
