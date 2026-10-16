<?php

namespace App\Services\WhatsApp;

use App\Models\WaConversation;
use Illuminate\Support\Collection;

/**
 * ما تُقيَّم عليه القاعدة (E37): النص المعلّق مطبَّعاً وعدد كلماته، وأنواع رسائله، والربط بعميل، والمواضيع،
 * والإحالات (إعلان Click-to-WhatsApp)، وهل الرقم لموظف. يُبنى من المحادثة في مسار الرد والمحاكاة، ومن مدخلات
 * المدير في «جرّب نصاً»، فما يُختبر في الصفحة هو ما يعمل فعلاً. لا شرط على الهاتف (قد يغيب منذ BSUID).
 */
final class AutoRuleContext
{
    public readonly int $words;

    /**
     * @param  list<string>  $msgTypes
     * @param  list<string>  $topics
     * @param  list<array<string, mixed>>  $referrals
     */
    public function __construct(
        public readonly string $norm,
        public readonly array $msgTypes = ['text'],
        public readonly bool $linked = false,
        public readonly array $topics = [],
        public readonly array $referrals = [],
        public readonly bool $staff = false,
    ) {
        $this->words = AutoRuleMatcher::words($norm);
    }

    /** الوارد المعلّق في المحادثة: نصه مجموعاً كما يقرؤه البوت، وأنواعه وإحالاته */
    public static function forConversation(WaConversation $conv, Collection $pending, ?string $text = null): self
    {
        return new self(
            AutoRuleMatcher::normalize($text ?? $pending->pluck('body')->implode("\n")),
            $pending->pluck('msg_type')->filter()->unique()->values()->all() ?: ['text'],
            (bool) $conv->financialClientId(),
            array_column(TopicRouter::topicsOf($conv), 'key'),
            $pending->pluck('referral')->filter(fn ($r) => is_array($r) && $r !== [])->values()->all(),
            (bool) $conv->user_id,
        );
    }
}
