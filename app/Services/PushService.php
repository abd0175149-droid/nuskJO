<?php

namespace App\Services;

use App\Models\FcmToken;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\WebPushConfig;

/**
 * إشعار النظام على أجهزة المستخدم (FCM) — يصل والموقع مغلق.
 *
 * الرسالة «بيانات فقط»: يعرضها عامل الخدمة (public/firebase-messaging-sw.js) بنفسه،
 * فلا يعرضها Firebase مرة ثانية. قيم البيانات نصوص كلها (شرط FCM).
 * التوكنات التي يرفضها FCM (جهاز حُذف منه الموقع أو أُلغي إذنه) تُحذف هنا.
 */
class PushService
{
    public static function toUser(int $userId, string $title, string $body, ?string $url = null, ?string $tag = null): int
    {
        // خط أخير: الإشعارات تحمل أسماء عملاء ومبالغ، فلا تصل لحساب معطّل
        if (!\App\Models\User::where('id', $userId)->where('is_active', true)->exists()) {
            return 0;
        }

        $tokens = FcmToken::where('user_id', $userId)->pluck('token')->all();
        if (!$tokens || !self::configured()) {
            return 0;
        }

        $message = CloudMessage::new()
            ->withData(array_filter([
                'title' => $title,
                'body' => $body,
                'url' => $url ?: '/',
                'tag' => $tag,
            ], fn ($v) => $v !== null && $v !== ''))
            ->withWebPushConfig(WebPushConfig::fromArray([
                'headers' => ['Urgency' => 'high', 'TTL' => '86400'],
            ]));

        $report = app(Messaging::class)->sendMulticast($message, $tokens);

        $dead = array_merge($report->invalidTokens(), $report->unknownTokens());
        if ($dead) {
            FcmToken::whereIn('token', $dead)->delete();
        }

        return $report->successes()->count();
    }

    /** ملف حساب الخدمة موجود؟ بدونه لا إرسال — والإشعار يبقى في الجرس */
    public static function configured(): bool
    {
        $project = config('firebase.default');
        $credentials = config("firebase.projects.{$project}.credentials");
        if (!$credentials) {
            return false;
        }
        if (str_starts_with($credentials, '{')) {
            return true; // محتوى JSON مباشرة في المتغير
        }
        // بنفس قاعدة kreait: المطلق يبدأ بـ / أو فيه :\ — وغيره نسبي لجذر المشروع
        $absolute = str_starts_with($credentials, '/') || str_contains($credentials, ':\\');

        return is_file($absolute ? $credentials : base_path($credentials));
    }
}
