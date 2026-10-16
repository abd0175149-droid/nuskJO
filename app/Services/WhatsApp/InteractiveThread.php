<?php

namespace App\Services\WhatsApp;

use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaChoiceSet;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\Outbound\InteractiveTypes;
use App\Support\FeatureFlags;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * حقول الخيط التفاعلية (E36) لـ WhatsAppController::messages: ما عُرض على العميل (choices: عناوين بلا معرّفات، وهل انتهت)،
 * وما اختاره (chosen: العنوان والصلاحية وسبب الرفض بالعربية)، ورد نموذج طلب التسعير (quote_form برابط الطلب لمن يملك
 * quotes.view). المجموعات والطلبات من المحادثة نفسها في استعلام واحد للصفحة؛ لا payload ولا معرّف ولا flow_token للواجهة.
 */
final class InteractiveThread
{
    /** @var array<int, WaChoiceSet> */
    private array $sets = [];

    /** @var array<int, string> رقم الطلب => request_number */
    private array $quotes = [];

    private function __construct(private readonly bool $canViewQuotes, private readonly bool $chips) {}

    /** @param  Collection<int, WaMessage>  $rows  تحتاج الأعمدة direction وmsg_type وpayload */
    public static function prepare(WaConversation $conv, Collection $rows, User $user): self
    {
        // الصندوق الجديد يعرض العناوين حبوباً (ChoiceChips)؛ القديم يبقى على سطر «[قائمة: …]» في النص
        $self = new self($user->can('quotes.view'), FeatureFlags::on('inbox_v2'));
        $setIds = $rows->filter(fn ($m) => $m->direction === 'out' && $m->msg_type === InteractiveTypes::TYPE)
            ->map(fn ($m) => (int) data_get($m->payload, '_meta.choice_set_id'))->filter()->unique()->values()->all();
        if ($setIds !== [] && Schema::hasTable('wa_choice_sets')) {
            $self->sets = WaChoiceSet::where('conversation_id', $conv->id)->whereIn('id', $setIds)
                ->get(['id', 'kind', 'expires_at', 'consumed_at'])->keyBy('id')->all();
        }
        $qrIds = $rows->filter(fn ($m) => $m->direction === 'in')->map(fn ($m) => (int) data_get($m->payload, '_flow.quote_request_id'))
            ->filter()->unique()->values()->all();
        if ($qrIds !== []) {
            $self->quotes = QuoteRequest::where('conversation_id', $conv->id)->whereIn('id', $qrIds)->pluck('request_number', 'id')->all();
        }

        return $self;
    }

    public function fields(WaMessage $m): array
    {
        $payload = (array) ($m->payload ?? []);
        if ($m->direction === 'out' && $m->msg_type === InteractiveTypes::TYPE && ($shown = InteractiveTypes::shown($payload))) {
            $setId = (int) data_get($payload, '_meta.choice_set_id');
            $set = $setId ? ($this->sets[$setId] ?? null) : null;
            // مجموعة حُذفت بالتقليم أو انتهت، أو نموذج عُبّئ: منتهية. أزرار ثابتة بلا مجموعة (موافقة الملفات) لا تنتهي هنا
            $expired = $setId > 0 && (!$set || $set->isExpired() || ($set->kind === 'quote_flow' && $set->consumed_at !== null));

            return ['choices' => array_filter(['type' => $shown['type'], 'header' => $shown['header'], 'items' => $shown['items'], 'expired' => $expired],
                fn ($v) => $v !== null)];
        }
        if ($m->direction !== 'in') {
            return [];
        }
        $out = [];
        $choice = $payload['_choice'] ?? null;
        if (is_array($choice)) {
            $valid = (bool) ($choice['valid'] ?? false);
            $out['chosen'] = [
                'label' => TextNormalizer::untrustedName((string) ($choice['label'] ?? $m->body), 120) ?? '—',
                'valid' => $valid,
                'reason_label' => $valid ? null : ChoiceResolver::reasonLabel($choice['reason'] ?? null),
            ];
        }
        $flow = $payload['_flow'] ?? null;
        if (is_array($flow)) {
            $id = (int) ($flow['quote_request_id'] ?? 0);
            $number = $id ? ($this->quotes[$id] ?? null) : null;
            $out['quote_form'] = array_filter([
                'valid' => (bool) ($flow['valid'] ?? false),
                'duplicate' => !empty($flow['duplicate']),
                'request_number' => $number,
                'url' => $number !== null && $this->canViewQuotes ? '/quote-requests?status=all&search='.rawurlencode($number) : null,
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    /** نص الفقاعة بلا سطر «[قائمة: …]» الأخير حين تُعرض العناوين حبوباً (يبقى في القاعدة لسياق النموذج والبحث) */
    public function body(WaMessage $m, ?string $body): ?string
    {
        if ($body === null || !$this->chips || $m->direction !== 'out' || $m->msg_type !== InteractiveTypes::TYPE || !InteractiveTypes::shown((array) ($m->payload ?? []))) {
            return $body;
        }

        return preg_replace('/\n\[(?:قائمة|أزرار|نموذج): [^\n]*\]$/u', '', $body) ?? $body;
    }
}
