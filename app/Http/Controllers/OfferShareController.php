<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\WaQrLink;
use App\Services\OfferCardService;
use App\Services\WhatsApp\OfferQrService;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تبويب «📤 المشاركة» في صفحة العرض (E38): رموز QR والروابط القصيرة الرسمية، وبطاقة الحالة 9:16 للنشر اليدوي.
 * العرض والتنزيل offers.view، والإنشاء والحذف والتوليد وطباعة الرمز offers.update، فوق وسيط المسار. الرمز يُربط بعرضه
 * دائماً (رمز عرض آخر 404). العلمان: wa_qr_links للرموز، وoffer_story_card لبطاقة الحالة، ومطفأ = 404.
 */
class OfferShareController extends Controller
{
    private function qrOn(): bool
    {
        return FeatureFlags::on('wa_qr_links') && OfferQrService::available();
    }

    private function storyOn(): bool
    {
        return FeatureFlags::on('offer_story_card') && OfferQrService::available();
    }

    private function link(Offer $offer, WaQrLink $link): WaQrLink
    {
        abort_unless($link->offer_id === $offer->id && $link->status !== 'deleted', 404);

        return $link;
    }

    /** GET api/offers/{offer}/share */
    public function index(Offer $offer): JsonResponse
    {
        abort_unless(auth()->user()->can('offers.view'), 403);
        abort_unless($this->qrOn() || $this->storyOn(), 404);

        $links = $this->qrOn()
            ? WaQrLink::where('offer_id', $offer->id)->where('status', '!=', 'deleted')->orderByDesc('id')->limit(100)->get()
            : collect();
        $usage = OfferQrService::usage($links->pluck('id')->all());
        $story = $offer->story_card_path;

        return response()->json([
            'flags' => ['qr' => $this->qrOn(), 'story' => $this->storyOn()],
            'for_bot' => Offer::forBot()->whereKey($offer->id)->exists(),
            'blockers' => collect($offer->forBotChecks())->reject(fn ($c) => $c['ok'])->pluck('label')->values(),
            'links' => $links->map(fn (WaQrLink $l) => self::row($l, $usage[$l->id] ?? null, $offer->card_qr_link_id === $l->id))->values(),
            'limit' => $this->qrOn() ? OfferQrService::limitState() : null,
            'channels' => array_intersect_key(WaQrLink::CHANNELS, array_flip(WaQrLink::OFFER_CHANNELS)),
            'story' => $this->storyOn() ? [
                'url' => $story ? Storage::disk('public')->url($story).'?v='.$offer->story_generated_at?->timestamp : null,
                'is_stale' => $offer->storyIsStale(),
                'generated_at' => $offer->story_generated_at?->toIso8601String(),
            ] : null,
            'can' => ['update' => auth()->user()->can('offers.update')],
        ]);
    }

    /** POST offers/{offer}/qr {channel, label} */
    public function storeQr(Request $request, Offer $offer): JsonResponse
    {
        abort_unless(auth()->user()->can('offers.update'), 403);
        abort_unless($this->qrOn(), 404);
        $data = $request->validate([
            'channel' => ['required', 'string', Rule::in(WaQrLink::OFFER_CHANNELS)],
            'label' => 'nullable|string|max:80',
        ], ['channel.*' => 'اختر قناة الرمز.', 'label.*' => 'الوصف الداخلي حتى 80 حرفاً.']);

        $r = OfferQrService::create($offer, $data['channel'], $data['label'] ?? null, auth()->user());

        return response()->json(array_diff_key($r, ['link' => 1]) + (isset($r['link']) ? ['link' => self::row($r['link']->fresh(), null, false)] : []), $r['ok'] ? 200 : 422);
    }

    /** DELETE offers/{offer}/qr/{link} */
    public function destroyQr(Offer $offer, WaQrLink $link): JsonResponse
    {
        abort_unless(auth()->user()->can('offers.update'), 403);
        abort_unless($this->qrOn(), 404);
        $r = OfferQrService::delete($this->link($offer, $link), auth()->user());

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** GET offers/{offer}/qr/{link}/{format}: PNG من public، وSVG من القرص الخاص مرفقاً بـ nosniff */
    public function downloadQr(Offer $offer, WaQrLink $link, string $format): StreamedResponse|JsonResponse
    {
        abort_unless(auth()->user()->can('offers.view'), 403);
        abort_unless($this->qrOn(), 404);

        return self::download($this->link($offer, $link), $format, "nusk-offer-{$offer->id}-qr-{$link->id}");
    }

    /** POST offers/{offer}/card-qr {link_id|null}: الرمز المطبوع على بطاقة A4، ولمس العرض يجعل بطاقته قديمة */
    public function toggleCardQr(Request $request, Offer $offer): JsonResponse
    {
        abort_unless(auth()->user()->can('offers.update'), 403);
        abort_unless($this->qrOn(), 404);
        $id = $request->validate(['link_id' => 'nullable|integer'])['link_id'] ?? null;
        if ($id !== null) {
            $link = WaQrLink::active()->whereKey($id)->where('offer_id', $offer->id)->first();
            abort_unless($link !== null, 404);
            if (!$link->qr_png_path) {
                return response()->json(['ok' => false, 'code' => 'QR_IMAGE', 'error' => 'صورة هذا الرمز غير محفوظة: نزّلها مرة أولاً ثم أعد المحاولة.'], 422);
            }
        }
        $before = $offer->card_qr_link_id;
        $offer->forceFill(['card_qr_link_id' => $id])->save();
        AuditLog::log(AuditAction::WaQrCardToggle, 'offer', $offer->id, $offer->title, ['from' => $before, 'to' => $id]);

        return response()->json(['ok' => true, 'card_qr_link_id' => $id]);
    }

    /** POST offers/{offer}/story */
    public function generateStory(Offer $offer): JsonResponse
    {
        abort_unless(auth()->user()->can('offers.update'), 403);
        abort_unless($this->storyOn(), 404);
        try {
            OfferCardService::generateStory($offer);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'code' => 'STORY_FAILED', 'error' => $e->getMessage()], 422);
        }
        AuditLog::log(AuditAction::OfferStoryGenerate, 'offer', $offer->id, $offer->title, ['auto' => false]);

        return response()->json(['ok' => true, 'url' => Storage::disk('public')->url($offer->story_card_path).'?v='.$offer->story_generated_at?->timestamp]);
    }

    /** GET offers/{offer}/story/download: يعيد التوليد إن كانت قديمة، ثم التنزيل للنشر اليدوي */
    public function downloadStory(Offer $offer): StreamedResponse|JsonResponse
    {
        abort_unless(auth()->user()->can('offers.view'), 403);
        abort_unless($this->storyOn(), 404);
        if (!Offer::forBot()->whereKey($offer->id)->exists()) {
            return response()->json(['ok' => false, 'code' => 'OFFER_NOT_FOR_BOT', 'error' => 'لا تنشر عرضاً لا يستطيع البوت الإجابة عنه.'], 422);
        }
        if ($offer->storyIsStale()) {
            try {
                OfferCardService::generateStory($offer);
            } catch (\RuntimeException $e) {
                return response()->json(['ok' => false, 'code' => 'STORY_FAILED', 'error' => $e->getMessage()], 422);
            }
            AuditLog::log(AuditAction::OfferStoryGenerate, 'offer', $offer->id, $offer->title, ['auto' => true]);
        }

        return Storage::disk('public')->download($offer->story_card_path, "nusk-offer-{$offer->id}-story.png");
    }

    /** صف رمز للواجهة: لا مسار ملف، والصورة من مسار التنزيل المحمي */
    public static function row(WaQrLink $l, ?array $usage, bool $onCard): array
    {
        $base = $l->offer_id ? "/offers/{$l->offer_id}/qr/{$l->id}" : "/whatsapp/qr-links/{$l->id}";

        return [
            'id' => $l->id,
            'channel' => $l->channel,
            'channel_label' => WaQrLink::CHANNELS[$l->channel] ?? $l->channel,
            'label' => $l->label,
            'status' => $l->status,
            'deep_link_url' => $l->deep_link_url,
            'prefilled_message' => $l->prefilled_message,
            'prefilled_len' => mb_strlen((string) $l->prefilled_message),
            'token_ok' => (bool) $l->token_ok,
            'last_error' => $l->status === 'failed' || ($l->status === 'active' && !$l->qr_png_path) ? $l->last_error : null,
            'png_url' => $l->status === 'active' ? "{$base}/png" : null,
            'svg_url' => $l->status === 'active' ? "{$base}/svg" : null,
            'has_png' => (bool) $l->qr_png_path,
            'on_card' => $onCard,
            'conversations' => (int) ($usage['conversations'] ?? 0),
            'quotes' => (int) ($usage['quotes'] ?? 0),
            'created_at' => $l->created_at?->toIso8601String(),
        ];
    }

    /** تنزيل الصورة بصيغتها (تُطلب من ميتا أول مرة إن لم تُحفظ) */
    public static function download(WaQrLink $link, string $format, string $name): StreamedResponse|JsonResponse
    {
        $r = OfferQrService::image($link, $format);
        if (!$r['ok']) {
            return response()->json($r, 422);
        }
        $svg = $format === 'svg';

        return Storage::disk($r['disk'])->download($r['path'], $name.($svg ? '.svg' : '.png'), [
            'Content-Type' => $svg ? 'image/svg+xml' : 'image/png',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
