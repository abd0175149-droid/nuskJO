<?php

namespace App\Services\Security;

use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** إخراج المستخدم من كل أجهزته: الجلسات، ورمز «تذكرني»، وإشعارات الجهاز. */
class SessionRevoker
{
    public static function revoke(User $user): void
    {
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        $user->fcmTokens()->delete();
    }

    /** بعد حذف المستخدم: جلساته ورموز أجهزته فقط (لا صف لتدوير رمزه). */
    public static function revokeDeleted(int $userId): void
    {
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $userId)->delete();
        }
        FcmToken::where('user_id', $userId)->delete();
    }
}
