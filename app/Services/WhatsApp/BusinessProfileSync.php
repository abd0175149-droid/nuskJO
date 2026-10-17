<?php

namespace App\Services\WhatsApp;

use App\Enums\AuditAction;
use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\CompanyInfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * ملف واتساب للأعمال (E31): قراءته من ميتا ومقارنته ببيانات الشركة (CompanyInfo، المصدر الواحد الذي يُحقن في موجّه
 * البوت)، ثم مزامنة الحقول المحددة بتأكيد وتدقيق. القيم تُحسب في الخادم دائماً: لا قيمة من المتصفح تصل ميتا.
 *
 * اسم العرض واسم المستخدم للنشاط من WhatsApp Manager وحده: لا new_display_name ولا register ولا username في النظام
 * (مراجعة ميتا، وإعادة تسجيل الرقم خلال 14 يوماً، والتحقق الثنائي). يحرس ذلك NoDisplayNameApiTest.
 */
final class BusinessProfileSync
{
    /** الحقول القابلة للمزامنة، بترتيب العرض */
    public const FIELDS = ['about', 'description', 'address', 'email', 'websites', 'vertical'];

    /** الحدود الرسمية بالأحرف (mb_strlen) */
    public const LIMITS = ['about' => 139, 'description' => 512, 'address' => 256, 'email' => 128, 'website' => 256];

    public const MAX_WEBSITES = 2;

    public const VERTICAL = 'TRAVEL';

    public const LABELS = [
        'about' => 'النبذة (about)', 'description' => 'الوصف', 'address' => 'العنوان', 'email' => 'البريد',
        'websites' => 'المواقع', 'vertical' => 'نوع النشاط', 'profile_picture_url' => 'الصورة',
    ];

    /** صورة الملف: النوع والحجم والأبعاد */
    public const PHOTO_MIMES = ['image/jpeg', 'image/png'];

    public const PHOTO_MAX_BYTES = 5 * 1024 * 1024;

    public const PHOTO_MIN_PX = 192;

    private const CACHE_KEY = 'wa:business-profile';

    private const CACHE_MINUTES = 10;

    /**
     * ما يجب أن يكون في ميتا، من بيانات الشركة وحدها. كل نص بلا محارف تحكم (TextNormalizer::untrustedName).
     *
     * @return array{about: ?string, description: ?string, address: ?string, email: ?string, websites: list<string>, vertical: string}
     */
    public static function desired(): array
    {
        $c = CompanyInfo::all();
        $text = fn ($v) => TextNormalizer::untrustedName($v, 4000);
        // الموقع الثاني بعد فيسبوك؛ أكثر من رابطين يُرفض لا يُقص
        $sites = preg_split('/[\s,،]+/u', trim((string) Setting::get('company_website')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [
            'about' => $text(Setting::get('wa_profile_about')),
            'description' => $text(Setting::get('wa_profile_description')),
            'address' => $text($c['address']),
            'email' => $text($c['email']),
            'websites' => array_values(array_filter([...$sites, trim((string) $c['facebook'])])),
            'vertical' => self::VERTICAL,
        ];
    }

    /**
     * لكل حقل: {current, desired, status} و status: same | different | missing_local | too_long | invalid.
     * المواقع تُقارن بلا ترتيب.
     */
    public static function diff(array $current, array $desired): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $cur = $current[$f] ?? ($f === 'websites' ? [] : null);
            $des = $desired[$f] ?? ($f === 'websites' ? [] : null);
            $status = self::problem($f, $des) ?? (self::same($f, $cur, $des) ? 'same' : 'different');
            $out[$f] = ['current' => $cur, 'desired' => $des, 'status' => $status];
        }

        return $out;
    }

    /** null: القيمة المحلية صالحة للإرسال */
    public static function problem(string $field, mixed $value): ?string
    {
        if ($field === 'vertical') {
            return null;
        }
        if ($field === 'websites') {
            $sites = (array) $value;
            if ($sites === []) {
                return 'missing_local';
            }
            if (count($sites) > self::MAX_WEBSITES) {
                return 'too_long';
            }
            foreach ($sites as $site) {
                if (mb_strlen((string) $site) > self::LIMITS['website']) {
                    return 'too_long';
                }
                if (!preg_match('#^https?://[^\s/$.?\#][^\s]*$#i', (string) $site)) {
                    return 'invalid';
                }
            }

            return null;
        }

        $v = (string) $value;
        if ($v === '') {
            return 'missing_local';
        }
        if (mb_strlen($v) > self::LIMITS[$field]) {
            return 'too_long';
        }
        // النبذة بلا تنسيق Markdown (ميتا ترفض * و _ و ` و ~)
        if ($field === 'about' && preg_match('/[*_`~]/u', $v)) {
            return 'invalid';
        }
        if ($field === 'email' && filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            return 'invalid';
        }

        return null;
    }

    /**
     * القراءة من ميتا والمقارنة (كاش 10 دقائق، و$refresh يتجاوزه). القراءة الفاشلة لا تُخزَّن.
     *
     * @return array{ok: bool, error?: ?string, code?: ?string, current?: array, desired?: array, diff?: array, identity?: array, fetched_at?: string}
     */
    public static function overview(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget(self::CACHE_KEY);
        }
        $cached = Cache::get(self::CACHE_KEY);
        if (!is_array($cached)) {
            $read = self::readMeta();
            if (!$read['ok']) {
                return $read + ['desired' => self::desired()];
            }
            $cached = $read;
            Cache::put(self::CACHE_KEY, $cached, now()->addMinutes(self::CACHE_MINUTES));
        }
        // المرغوب من الإعدادات الآن دائماً (يتغيّر بلا انتظار الكاش)
        $desired = self::desired();

        return $cached + ['desired' => $desired, 'diff' => self::diff($cached['current'], $desired)];
    }

    /**
     * مزامنة الحقول المحددة: تعيد حساب المرغوب والحالي في الخادم، وترفض too_long وinvalid وmissing_local، وترسل
     * المحدد وحده، ثم تقرأ الملف من جديد للتحقق، وتدقيق صارم بالقيم السابقة (يسمح بالإرجاع يدوياً).
     *
     * @param  list<string>  $fields
     * @return array{ok: bool, code?: ?string, error?: ?string, rejected?: array<string, string>, verified?: bool, diff?: array}
     */
    public static function sync(array $fields): array
    {
        $fields = array_values(array_intersect(self::FIELDS, $fields));
        if ($fields === []) {
            return ['ok' => false, 'code' => 'NO_FIELDS', 'error' => 'اختر حقلاً واحداً على الأقل.'];
        }
        $before = self::readMeta();
        if (!$before['ok']) {
            return $before;
        }
        $desired = self::desired();
        $rejected = [];
        $payload = [];
        foreach ($fields as $f) {
            if ($problem = self::problem($f, $desired[$f])) {
                $rejected[$f] = $problem;

                continue;
            }
            $payload[$f] = $desired[$f];
        }
        if ($rejected) {
            return ['ok' => false, 'code' => 'FIELDS_REJECTED', 'rejected' => $rejected,
                'error' => 'لا تُرسل حقول طويلة أو غير صالحة أو فارغة في بيانات الشركة: '.implode('، ', array_map(fn ($f) => self::LABELS[$f], array_keys($rejected)))];
        }

        $r = WhatsAppChannel::updateBusinessProfile($payload);
        if (!$r['ok']) {
            return $r;
        }
        Cache::forget(self::CACHE_KEY);
        $after = self::readMeta();
        $fresh = $after['ok'] ? $after['current'] : [];
        $verified = $after['ok'] && collect($fields)->every(fn ($f) => self::same($f, $fresh[$f] ?? null, $desired[$f]));

        AuditLog::log(AuditAction::WaBusinessProfileUpdate, 'wa_business_profile', null, null, [
            'fields' => $fields,
            'before' => array_intersect_key($before['current'], array_flip($fields)),
            'after' => $after['ok'] ? array_intersect_key($fresh, array_flip($fields)) : null,
            'verified' => $verified,
        ], AuditSeverity::Warn);
        if ($after['ok']) {
            Cache::put(self::CACHE_KEY, $after, now()->addMinutes(self::CACHE_MINUTES));
        }

        return ['ok' => true, 'code' => null, 'error' => null, 'verified' => $verified, 'diff' => self::diff($fresh, $desired)];
    }

    /**
     * صورة الملف: ملف مرفوع أو شعار الشركة. JPEG أو PNG وحدهما (finfo)، و5MB، ومربعة تقريباً، و192px على الأقل.
     *
     * @return array{ok: bool, code?: ?string, error?: ?string, status?: int}
     */
    public static function photo(?string $bytes, string $source): array
    {
        if ($bytes === null && $source === 'company_logo') {
            $path = (string) Setting::get('company_logo');
            $bytes = $path !== '' && Storage::disk('public')->exists($path) ? Storage::disk('public')->get($path) : null;
        }
        if ($bytes === null || $bytes === '') {
            return ['ok' => false, 'code' => 'NO_PHOTO', 'error' => 'لا صورة: ارفع ملفاً أو أضف شعار الشركة في الإعدادات.', 'status' => 422];
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (!in_array($mime, self::PHOTO_MIMES, true)) {
            return ['ok' => false, 'code' => 'PHOTO_TYPE', 'error' => 'الصورة JPEG أو PNG فقط.', 'status' => 422];
        }
        if (strlen($bytes) > self::PHOTO_MAX_BYTES) {
            return ['ok' => false, 'code' => 'PHOTO_SIZE', 'error' => 'أقصى حجم للصورة 5 ميغابايت.', 'status' => 422];
        }
        $size = @getimagesizefromstring($bytes);
        [$w, $h] = $size ? [(int) $size[0], (int) $size[1]] : [0, 0];
        if ($w < self::PHOTO_MIN_PX || $h < self::PHOTO_MIN_PX) {
            return ['ok' => false, 'code' => 'PHOTO_SMALL', 'error' => 'الصورة 192×192 بكسل على الأقل.', 'status' => 422];
        }
        if (abs($w - $h) > 0.1 * max($w, $h)) {
            return ['ok' => false, 'code' => 'PHOTO_SHAPE', 'error' => 'الصورة مربعة تقريباً (يقصّها واتساب دائرة).', 'status' => 422];
        }

        $up = WhatsAppChannel::uploadProfilePicture($bytes, $mime, 'profile.'.($mime === 'image/png' ? 'png' : 'jpg'));
        if (!$up['ok']) {
            return $up + ['status' => $up['code'] === 'APP_ID_MISSING' ? 409 : 422];
        }
        $r = WhatsAppChannel::updateBusinessProfile(['profile_picture_handle' => $up['handle']]);
        if (!$r['ok']) {
            return $r + ['status' => 422];
        }
        Cache::forget(self::CACHE_KEY);
        AuditLog::log(AuditAction::WaBusinessProfilePhoto, 'wa_business_profile', null, null,
            ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'source' => $source, 'width' => $w, 'height' => $h], AuditSeverity::Warn);

        return ['ok' => true, 'code' => null, 'error' => null];
    }

    /** @return array{ok: bool, code?: ?string, error?: ?string, current?: array, identity?: array, fetched_at?: string} */
    private static function readMeta(): array
    {
        $profile = WhatsAppChannel::getBusinessProfile();
        if (!$profile['ok']) {
            return ['ok' => false, 'code' => $profile['code'], 'error' => $profile['error'], 'action' => $profile['action'] ?? null];
        }
        $identity = WhatsAppChannel::getPhoneIdentity();
        $d = $profile['data'];

        return [
            'ok' => true,
            'current' => [
                'about' => self::str($d['about'] ?? null),
                'description' => self::str($d['description'] ?? null),
                'address' => self::str($d['address'] ?? null),
                'email' => self::str($d['email'] ?? null),
                'websites' => array_values(array_filter(array_map('strval', (array) ($d['websites'] ?? [])))),
                'vertical' => self::str($d['vertical'] ?? null),
                'profile_picture_url' => self::str($d['profile_picture_url'] ?? null),
            ],
            'identity' => $identity['ok'] ? $identity['data'] : [],
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    private static function same(string $field, mixed $a, mixed $b): bool
    {
        if ($field === 'websites') {
            $norm = fn ($list) => collect((array) $list)->map(fn ($u) => rtrim(mb_strtolower(trim((string) $u)), '/'))->sort()->values()->all();

            return $norm($a) === $norm($b);
        }

        return trim((string) $a) === trim((string) $b);
    }

    private static function str(mixed $v): ?string
    {
        return is_scalar($v) && (string) $v !== '' ? (string) $v : null;
    }
}
