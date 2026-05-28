<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('numbering_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 10);
            $table->string('date', 8);
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->unique(['prefix', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numbering_sequences');
    }
};
