<?php

namespace App\Services\WhatsApp;

use App\Enums\AuditAction;
use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaQrLink;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * رموز QR والروابط القصيرة للعروض (E38).
 *
 * - الرسالة المعبأة يبنيها الكود من عنوان العرض العام والمرجع [N-45/Q7] وحدهما، 140 حرفاً على الأكثر، والمرجع
 *   لا يُقص أبداً. لا اسم ولا هاتف ولا أي بيان عن عميل، والوصف الداخلي (label) لا يُرسل.
 * - لا رمز إلا لعرض يقرؤه البوت (Offer::forBot)، فلا نرمّز ما لا يستطيع البوت الإجابة عنه.
 * - حد ميتا 2000 رمز ورابط للرقم: الرفض عنده بلا نداء، والتنبيه من 1800.
 * - إغلاق العرض أو انتهاء مدته لا يحذف رموزه (قد تكون مطبوعة): المسح بعدها يصل، والبوت يعتذر ويعرض البدائل.
 * - صف «معلّق» قبل نداء ميتا للحصول على رقمه (المرجع)، بلا معاملة تمسك القاعدة أثناء النداء.
 */
final class OfferQrService
{
    public const PREFILLED_MAX = 140;

    private static ?bool $available = null;

    /** الجدول والعمود موجودان (بين النشر والهجرة 2026_10_14_000038 لا يوجدان) */
    public static function available(): bool
    {
        return self::$available ??= Schema::hasTable('wa_qr_links') && Schema::hasColumn('offers', 'card_qr_link_id');
    }

    /**
     * «مرحباً، أريد تفاصيل عرض «{title}» [N-45/Q7]». العنوان يُقص بـ «…» حتى يبقى الكل 140 حرفاً أو أقل.
     * والرمز العام: «مرحباً، أريد الاستفسار عن عروضكم [Q12]».
     */
    public static function prefilled(?Offer $o, WaQrLink $link, bool $latin = false): string
    {
        $token = OfferRef::token($o?->id, (int) $link->id);
        if (!$o) {
            return $latin ? "Hello, I'd like to ask about your offers {$token}" : "مرحباً، أريد الاستفسار عن عروضكم {$token}";
        }
        if ($latin) {
            return "Hello, I'd like details of offer {$token}";
        }

        $title = (string) TextNormalizer::untrustedName((string) $o->title, 500);
        $build = fn (string $t) => "مرحباً، أريد تفاصيل عرض «{$t}» {$token}";
        if (mb_strlen($build($title)) <= self::PREFILLED_MAX) {
            return $build($title);
        }
        $room = self::PREFILLED_MAX - mb_strlen($build('')) - 1;

        return $build(rtrim(mb_substr($title, 0, max(0, $room))).'…');
    }

    /** @return array{count: int, max: int, warn: int} */
    public static function limitState(): array
    {
        return ['count' => WaQrLink::counted()->count(), 'max' => WaQrLink::MAX_PER_NUMBER, 'warn' => WaQrLink::WARN_AT];
    }

    /**
     * ينشئ الرمز عند ميتا ويحفظه كما أعادته، وينزّل صورة PNG فوراً.
     *
     * @return array{ok: bool, code: ?string, error: ?string, link?: WaQrLink, warning?: ?string}
     */
    public static function create(?Offer $o, string $channel, ?string $label, User $by): array
    {
        if (!self::available()) {
            return self::refuse('QR_UNAVAILABLE', 'رموز QR غير متاحة قبل تشغيل الهجرة.');
        }
        if (!FeatureFlags::on('wa_qr_links')) {
            return self::refuse('QR_DISABLED', 'رموز QR مطفأة حالياً.');
        }
        $s = WaBotSetting::current();
        if (!$s->channelReady()) {
            return self::refuse(WhatsAppChannel::ERR_NOT_CONFIGURED, 'قناة الواتساب غير مهيّأة (رمز الوصول أو معرّف الرقم ناقص).');
        }
        if ($s->sendingSuspended()) {
            return self::refuse(WhatsAppChannel::ERR_SUSPENDED, 'الإرسال موقوف حالياً (قفل طارئ): لا تعديل للرموز حتى يُرفع.');
        }
        if ($o && !Offer::forBot()->whereKey($o->id)->exists()) {
            return self::refuse('OFFER_NOT_FOR_BOT', 'لا رمز لعرض لا يستطيع البوت الإجابة عنه: راجع شروط الظهور في «ما يراه البوت».');
        }
        $count = WaQrLink::counted()->count();
        if ($count >= WaQrLink::MAX_PER_NUMBER) {
            return self::refuse('QR_LIMIT', 'بلغنا حد ميتا: 2000 رمز ورابط للرقم. احذف رموزاً لا تُستعمل أولاً.');
        }
        $warning = $count + 1 >= WaQrLink::WARN_AT ? 'اقتربنا من حد ميتا: '.($count + 1).' من '.WaQrLink::MAX_PER_NUMBER.' رمز ورابط.' : null;

        $link = WaQrLink::create([
            'offer_id' => $o?->id, 'channel' => $channel, 'label' => $label ? mb_substr(trim($label), 0, 80) : null,
            'prefilled_message' => '', 'status' => 'pending', 'created_by' => $by->id,
        ]);
        $prefilled = self::prefilled($o, $link);
        $link->forceFill(['prefilled_message' => $prefilled])->save();

        $r = WhatsAppChannel::createQrdl($prefilled, 'PNG', $link->id);
        if (!$r['ok']) {
            $link->forceFill(['status' => 'failed', 'last_error' => mb_substr($r['code'].': '.$r['error'], 0, 191)])->save();

            return $r + ['link' => $link];
        }

        $d = $r['data'];
        $returned = $d['prefilled_message'] !== '' ? $d['prefilled_message'] : $prefilled;
        $png = $d['qr_image_url'] ? QrImageStore::fetch($d['qr_image_url'], 'PNG') : null;
        $link->forceFill([
            'code' => $d['code'],
            'deep_link_url' => $d['deep_link_url'],
            'prefilled_message' => mb_substr($returned, 0, 160),
            'token_ok' => OfferRef::matches($returned, $o?->id, (int) $link->id),
            'qr_png_path' => $png,
            'status' => 'active',
            'last_error' => $png ? null : 'QR_IMAGE: تعذّر حفظ صورة الرمز من ميتا.',
        ])->save();
        AuditLog::log(AuditAction::WaQrCreate, 'wa_qr_link', $link->id, $link->label,
            ['offer_id' => $o?->id, 'channel' => $channel, 'code' => $d['code'], 'token_ok' => $link->token_ok]);

        return ['ok' => true, 'code' => null, 'error' => null, 'link' => $link, 'warning' => $warning];
    }

    /**
     * يحذفه عند ميتا ثم عندنا. الرموز المطبوعة تتوقف عن فتح المحادثة برسالة معبأة. ما لم يصل ميتا (فاشل أو معلّق)
     * يُحذف عندنا وحدنا.
     *
     * @return array{ok: bool, code: ?string, error: ?string}
     */
    public static function delete(WaQrLink $l, User $by): array
    {
        if ($l->status === 'deleted') {
            return self::refuse('QR_DELETED', 'حُذف هذا الرمز من قبل.');
        }
        $gone = false;
        if ($l->code) {
            $r = WhatsAppChannel::deleteQrdl($l->code, $l->id);
            if (!$r['ok']) {
                return $r;
            }
            $gone = (bool) ($r['gone'] ?? false);
        }

        [$png, $svg] = [$l->qr_png_path, $l->qr_svg_path];
        DB::transaction(function () use ($l, $gone) {
            $l->forceFill(['status' => 'deleted', 'qr_png_path' => null, 'qr_svg_path' => null])->save();
            // الرمز المطبوع على بطاقة A4 يُزال، ولمس العرض يجعل بطاقته قديمة فتُعاد بلا رمز
            Offer::where('card_qr_link_id', $l->id)->get()->each(fn (Offer $o) => $o->forceFill(['card_qr_link_id' => null])->save());
            AuditLog::log(AuditAction::WaQrDelete, 'wa_qr_link', $l->id, $l->label,
                ['offer_id' => $l->offer_id, 'channel' => $l->channel, 'code' => $l->code, 'gone_at_meta' => $gone], AuditSeverity::Warn);
        });
        QrImageStore::forget($png, $svg);

        return ['ok' => true, 'code' => null, 'error' => null];
    }

    /**
     * صورة الرمز بصيغتها: المحفوظة، أو تُطلب من ميتا للرمز نفسه (التعديل يعيد qr_image_url جديداً) ثم تُحفظ.
     *
     * @return array{ok: bool, code: ?string, error: ?string, path?: string, disk?: string}
     */
    public static function image(WaQrLink $l, string $format): array
    {
        $svg = strtoupper($format) === 'SVG';
        $disk = $svg ? 'local' : 'public';
        $path = $svg ? $l->qr_svg_path : $l->qr_png_path;
        if ($path && Storage::disk($disk)->exists($path)) {
            return ['ok' => true, 'code' => null, 'error' => null, 'path' => $path, 'disk' => $disk];
        }
        if ($l->status !== 'active' || !$l->code) {
            return self::refuse('QR_INACTIVE', 'هذا الرمز غير فعّال.');
        }
        $r = WhatsAppChannel::updateQrdl($l->code, $l->prefilled_message, $svg ? 'SVG' : 'PNG', $l->id);
        if (!$r['ok']) {
            return $r;
        }
        $path = $r['data']['qr_image_url'] ? QrImageStore::fetch($r['data']['qr_image_url'], $svg ? 'SVG' : 'PNG') : null;
        if (!$path) {
            return self::refuse('QR_IMAGE', 'تعذّر تنزيل صورة الرمز من ميتا. أعد المحاولة بعد قليل.');
        }
        $l->forceFill([$svg ? 'qr_svg_path' : 'qr_png_path' => $path] + ($svg ? [] : ['last_error' => null]))->save();

        return ['ok' => true, 'code' => null, 'error' => null, 'path' => $path, 'disk' => $disk];
    }

    /**
     * محادثات بدأت من كل رمز، وطلبات تسعير أُنشئت للمحادثة نفسها بعد صف الدخول (قياس داخلي تقريبي).
     *
     * @param  list<int>  $linkIds
     * @return array<int, array{conversations: int, quotes: int}>
     */
    public static function usage(array $linkIds, int $days = 30): array
    {
        if ($linkIds === [] || !Schema::hasTable('wa_conversation_entries')) {
            return [];
        }
        $rows = DB::table('wa_conversation_entries as e')
            ->leftJoin('quote_requests as q', function ($j) {
                $j->on('q.conversation_id', '=', 'e.conversation_id')->on('q.created_at', '>=', 'e.created_at')
                    ->where(fn ($w) => $w->whereColumn('q.offer_id', 'e.offer_id')->orWhereNull('q.offer_id')->orWhereNull('e.offer_id'));
            })
            ->whereIn('e.qr_link_id', $linkIds)
            ->where('e.created_at', '>=', now()->subDays($days))
            ->groupBy('e.qr_link_id')
            ->selectRaw('e.qr_link_id, COUNT(DISTINCT e.conversation_id) as conversations, COUNT(DISTINCT q.id) as quotes')
            ->get();

        return $rows->mapWithKeys(fn ($r) => [(int) $r->qr_link_id => ['conversations' => (int) $r->conversations, 'quotes' => (int) $r->quotes]])->all();
    }

    private static function refuse(string $code, string $error): array
    {
        return ['ok' => false, 'code' => $code, 'error' => $error, 'meta_code' => null];
    }
}
