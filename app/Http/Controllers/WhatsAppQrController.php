<?php

namespace App\Http\Controllers;

use App\Models\WaQrLink;
use App\Services\WhatsApp\OfferQrService;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * الرموز العامة بلا عرض (E38): ملصق المكتب وصفحات التواصل، برسالة «مرحباً، أريد الاستفسار عن عروضكم [Q12]».
 * whatsapp.settings في كل دالة، والعلم wa_qr_links مطفأ = 404، ورمز عرضٍ ما لا يُدار من هنا (404).
 */
class WhatsAppQrController extends Controller
{
    private function authorizeQr(): void
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        abort_unless(FeatureFlags::on('wa_qr_links') && OfferQrService::available(), 404);
    }

    private function general(WaQrLink $link): WaQrLink
    {
        abort_unless($link->isGeneral() && $link->status !== 'deleted', 404);

        return $link;
    }

    /** GET api/whatsapp/qr-links */
    public function index(): JsonResponse
    {
        $this->authorizeQr();
        $links = WaQrLink::whereNull('offer_id')->where('status', '!=', 'deleted')->orderByDesc('id')->limit(100)->get();
        $usage = OfferQrService::usage($links->pluck('id')->all());

        return response()->json([
            'links' => $links->map(fn (WaQrLink $l) => OfferShareController::row($l, $usage[$l->id] ?? null, false))->values(),
            'limit' => OfferQrService::limitState(),
            'channels' => array_intersect_key(WaQrLink::CHANNELS, array_flip(WaQrLink::GENERAL_CHANNELS)),
        ]);
    }

    /** POST api/whatsapp/qr-links {channel, label} */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeQr();
        $data = $request->validate([
            'channel' => ['required', 'string', Rule::in(WaQrLink::GENERAL_CHANNELS)],
            'label' => 'nullable|string|max:80',
        ], ['channel.*' => 'اختر قناة الرمز.', 'label.*' => 'الوصف الداخلي حتى 80 حرفاً.']);

        $r = OfferQrService::create(null, $data['channel'], $data['label'] ?? null, auth()->user());

        return response()->json(array_diff_key($r, ['link' => 1]) + (isset($r['link']) ? ['link' => OfferShareController::row($r['link']->fresh(), null, false)] : []), $r['ok'] ? 200 : 422);
    }

    /** DELETE api/whatsapp/qr-links/{link} */
    public function destroy(WaQrLink $link): JsonResponse
    {
        $this->authorizeQr();
        $r = OfferQrService::delete($this->general($link), auth()->user());

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** GET whatsapp/qr-links/{link}/{format} */
    public function download(WaQrLink $link, string $format): StreamedResponse|JsonResponse
    {
        $this->authorizeQr();

        return OfferShareController::download($this->general($link), $format, "nusk-qr-{$link->id}");
    }
}
