<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaPlace;
use App\Support\FeatureFlags;
use Illuminate\Support\Collection;

/**
 * حقول الخيط الغنية (E33) لـ WhatsAppController::messages: الاقتباس بمعرّفنا الداخلي، والتفاعلات على أهدافها، والتصحيح،
 * والموقع، وبطاقات الاتصال، وما يُسمح به لكل رسالة. كل حل لـ wamid داخل المحادثة نفسها في استعلام واحد للصفحة،
 * فلا يُكشف مقتطف من محادثة أخرى. لا wamid ولا payload يخرجان للواجهة.
 */
final class RichThread
{
    /** @var array<string, WaMessage> wamid => صف من المحادثة نفسها (المقتبس وأهداف التفاعلات) */
    private array $byWamid = [];

    /** @var array<int, true> معرّفات صفوف الصفحة */
    private array $pageIds = [];

    private function __construct(
        private readonly array $features,
        private readonly bool $canReply,
    ) {}

    /** أعلام الواجهة: quote وreact وpreview وshare */
    public static function features(): array
    {
        return [
            'quote' => FeatureFlags::on('wa_reply_quote'),
            'react' => FeatureFlags::on('wa_staff_reactions'),
            'preview' => FeatureFlags::on('wa_link_preview'),
            'share' => FeatureFlags::on('wa_share_cards'),
        ];
    }

    /** @param  Collection<int, WaMessage>  $rows  تحتاج الأعمدة wamid وpayload */
    public static function prepare(WaConversation $conv, Collection $rows, User $user): self
    {
        $self = new self(self::features(), $user->can('whatsapp.reply'));
        $wamids = [];
        foreach ($rows as $m) {
            $self->pageIds[(int) $m->id] = true;
            if ($m->reply_to_wamid) {
                $wamids[] = (string) $m->reply_to_wamid;
            }
            if ($m->msg_type === 'reaction' && ($t = self::reactionTarget($m))) {
                $wamids[] = $t;
            }
        }
        $wamids = array_values(array_unique($wamids));
        if ($wamids !== []) {
            $self->byWamid = $conv->messages()->whereIn('wamid', $wamids)->get(['id', 'wamid', 'direction', 'msg_type', 'body', 'payload'])
                ->keyBy('wamid')->all();
        }

        return $self;
    }

    /** صف تفاعل هدفه في الصفحة نفسها لا يُعاد فقاعة (شارته على الهدف) */
    public function keep(WaMessage $m): bool
    {
        if ($m->msg_type !== 'reaction') {
            return true;
        }
        $target = $this->target($m);

        return !($target && isset($this->pageIds[(int) $target->id]));
    }

    public function fields(WaMessage $m): array
    {
        $payload = (array) ($m->payload ?? []);
        $out = [];

        // الاقتباس بمعرّفنا لا بـ wamid، والمقتطف من المحادثة نفسها وحدها
        $quoted = $m->reply_to_wamid ? ($this->byWamid[(string) $m->reply_to_wamid] ?? null) : null;
        if ($quoted && $quoted->msg_type !== 'reaction') {
            $out['reply_ref'] = ['id' => (int) $quoted->id, 'snippet' => self::snippet($quoted, 80), 'direction' => $quoted->direction];
        }
        if (!empty($payload['_context_dropped'])) {
            $out['quote_dropped'] = true;
        }

        if ($m->msg_type === 'reaction') {
            $target = $this->target($m);
            $out += $target
                ? ['target_id' => (int) $target->id, 'target_reactions' => (object) ReactionProjector::forThread((array) ($target->payload ?? []))]
                : ['orphan' => true];
        } elseif ($r = ReactionProjector::forThread($payload)) {
            $out['reactions'] = $r;
        }

        // التصحيح: «أُرسل تصحيح ↓» على الأصل، والتصحيح يشير إليه. لا كلمة «عُدّلت» في أي مكان
        if ($ids = array_values(array_filter(array_map(fn ($c) => (int) data_get($c, 'message_id'), (array) ($payload['_corrected_by'] ?? []))))) {
            $out['corrected_by'] = $ids;
        }
        if ($corrects = (int) data_get($payload, '_meta.corrects_message_id')) {
            $out['corrects_id'] = $corrects;
        }

        if ($m->msg_type === 'location' && ($loc = self::location($payload))) {
            $out['location'] = $loc;
        }
        if ($m->msg_type === 'contacts') {
            $out['contact_cards'] = $this->contactCards($payload);
        }

        $f = $this->features;
        $out['can_quote'] = $f['quote'] && filled($m->wamid) && $m->msg_type !== 'reaction' && !($m->source === 'system' && $m->direction === 'in');
        $out['can_react'] = $f['react'] && $m->direction === 'in' && $m->source === 'customer' && filled($m->wamid)
            && !in_array($m->msg_type, ['reaction', 'system'], true)
            && $m->created_at !== null && $m->created_at->gte(now()->subDays((int) config('whatsapp.reaction_max_days', 30)));
        $out['can_correct'] = $f['quote'] && $m->direction === 'out' && in_array($m->source, ['staff', 'bot', 'system'], true)
            && in_array($m->msg_type, ['text', 'image', 'document'], true) && filled($m->wamid)
            && !in_array($m->status, ['failed', 'queued', 'unknown', 'blocked'], true);

        return $out;
    }

    /** body بطاقة قديمة (قبل E33) قد يحمل رقماً: لمن لا يملك الرد يُقنّع */
    public function body(WaMessage $m): ?string
    {
        if ($m->msg_type === 'contacts' && !$this->canReply) {
            return preg_replace('/\s*—?\s*\+?\d[\d\s\-().]{5,}\d/u', '', (string) $m->body);
        }

        return $m->body;
    }

    private function target(WaMessage $m): ?WaMessage
    {
        $wamid = self::reactionTarget($m);
        $t = $wamid ? ($this->byWamid[$wamid] ?? null) : null;

        return $t && $t->msg_type !== 'reaction' ? $t : null;
    }

    /** هدف التفاعل: الوارد من ميتا (reaction.message_id أو _reaction_to)، والصادر من حمولتنا */
    private static function reactionTarget(WaMessage $m): ?string
    {
        $t = data_get($m->payload, 'reaction.message_id') ?? data_get($m->payload, '_reaction_to');

        return is_string($t) && $t !== '' ? $t : null;
    }

    private static function snippet(WaMessage $m, int $max): string
    {
        $body = trim((string) $m->body);
        if ($m->msg_type === 'contacts') {
            $body = preg_replace('/\s*—?\s*\+?\d[\d\s\-().]{5,}\d/u', '', $body) ?? $body;
        }

        return mb_substr($body, 0, $max);
    }

    /** الموقع: أرقام فقط، ورابط الخرائط يُبنى هنا من float لا من نص العميل */
    private static function location(array $payload): ?array
    {
        $loc = (array) ($payload['location'] ?? []);
        if (!is_numeric($loc['latitude'] ?? null) || !is_numeric($loc['longitude'] ?? null)) {
            return null;
        }
        $lat = (float) $loc['latitude'];
        $lng = (float) $loc['longitude'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        return [
            'lat' => round($lat, 7), 'lng' => round($lng, 7),
            'name' => TextNormalizer::untrustedName($loc['name'] ?? null, 120),
            'address' => TextNormalizer::untrustedName($loc['address'] ?? null, 200),
            'maps_url' => WaPlace::mapsUrlFor($lat, $lng),
        ];
    }

    /** البطاقات بالاسم، والأرقام لمن يملك whatsapp.reply وحده */
    private function contactCards(array $payload): array
    {
        $out = [];
        foreach (array_slice((array) ($payload['contacts'] ?? []), 0, 10) as $c) {
            $card = ['name' => TextNormalizer::untrustedName(data_get($c, 'name.formatted_name'), 120) ?? '—'];
            if ($this->canReply) {
                $card['phones'] = array_values(array_filter(array_map(
                    fn ($p) => is_string($v = data_get($p, 'phone') ?? data_get($p, 'wa_id')) ? mb_substr($v, 0, 30) : null,
                    array_slice((array) data_get($c, 'phones', []), 0, 5))));
            }
            $out[] = $card;
        }

        return $out;
    }
}
