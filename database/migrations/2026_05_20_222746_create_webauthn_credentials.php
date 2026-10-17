<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول مفاتيح المرور القديم (laragear/webauthn). كان الملف يستدعي صنف الحزمة نفسها، فإزالتها كانت ستُفشل migrate
 * على قاعدة فارغة. صار مخططاً صريحاً مطابقاً لما تنشئه الحزمة، بالاسم نفسه (فلا تتغير حالة migrations في الإنتاج)
 * ومحروساً بـ hasTable (E27). الجدول فارغ ولا يستعمله شيء بعد؛ حذفه بهجرة تنظيف بقرار.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webauthn_credentials')) {
            return;
        }

        Schema::create('webauthn_credentials', function (Blueprint $table) {
            $table->string('id', 510)->primary();
            $table->morphs('authenticatable', 'webauthn_user_index');
            $table->uuid('user_id');
            $table->string('alias')->nullable();
            $table->unsignedBigInteger('counter')->nullable();
            $table->string('rp_id');
            $table->string('origin');
            $table->json('transports')->nullable();
            $table->uuid('aaguid')->nullable();
            $table->text('public_key');
            $table->string('attestation_format')->default('none');
            $table->json('certificates')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webauthn_credentials');
    }
};
