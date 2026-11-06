<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Services\OfferCardService;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تبويب «📤 المشاركة» في صفحة العرض (E38): بطاقة الحالة 9:16 للنشر اليدوي. رموز QR الرسمية من ميتا (message_qrdls)
 * أُزيلت مع ناقل ميتا (2026-11-06). العرض والتنزيل offers.view، والتوليد offers.update. العلم offer_story_card، ومطفأ = 404.
 */
class OfferShareController extends Controller
{
    private function storyOn(): bool
    {
        return FeatureFlags::on('offer_story_card');
    }

    /** GET api/offers/{offer}/share */
    public function index(Offer $offer): JsonResponse
    {
        abort_unless(auth()->user()->can('offers.view'), 403);
        abort_unless($this->storyOn(), 404);
        $story = $offer->story_card_path;

        return response()->json([
            'for_bot' => Offer::forBot()->whereKey($offer->id)->exists(),
            'blockers' => collect($offer->forBotChecks())->reject(fn ($c) => $c['ok'])->pluck('label')->values(),
            'story' => [
                'url' => $story ? Storage::disk('public')->url($story).'?v='.$offer->story_generated_at?->timestamp : null,
                'is_stale' => $offer->storyIsStale(),
                'generated_at' => $offer->story_generated_at?->toIso8601String(),
            ],
            'can' => ['update' => auth()->user()->can('offers.update')],
        ]);
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
}
