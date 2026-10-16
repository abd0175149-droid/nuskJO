<?php

namespace Tests\Unit\WhatsApp;

use App\Models\User;
use App\Models\WaAutoRule;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\AutoRuleContext;
use App\Services\WhatsApp\AutoRuleMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

/**
 * مطابقة القواعد الحتمية (E37): تطبيع واحد للرسالة والقيم، وكلمات كاملة حرفية، وشروط AND، والقاعدة التالفة
 * تُتخطى وحدها وتُعلَّم بسطر سجل واحد في اليوم.
 */
class AutoRuleMatcherTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    private function rule(array $attrs = []): WaAutoRule
    {
        return WaAutoRule::create($attrs + [
            'name' => 'الموقع', 'is_active' => true, 'match_type' => 'any_of', 'patterns' => ['موقعكم', 'الموقع'],
            'reply_text_ar' => 'مكتبنا في {company.address}', 'max_words' => 6,
        ]);
    }

    private function ctx(string $text, array $extra = []): AutoRuleContext
    {
        return new AutoRuleContext(AutoRuleMatcher::normalize($text), ...$extra);
    }

    public static function sameAfterNormalising(): array
    {
        return [
            'punctuation' => ['وين موقعكم؟', 'وين موقعكم'],
            'command slash' => ['/الموقع', 'الموقع'],
            'indic digits' => ['٧', '7'],
            'persian digits' => ['۷ اشهر', '7 أشهر'],
            'diacritics and taa marbuta' => ['مكة المكرّمة', 'مكه المكرمه'],
            'hamza and tatweel' => ['أيــن الموقع', 'اين الموقع'],
            'spaces' => ["  وين \n موقعكم  ", 'وين موقعكم'],
            'latin case' => ['Where Is Your OFFICE?', 'where is your office'],
        ];
    }

    #[DataProvider('sameAfterNormalising')]
    public function test_normalisation_unifies_what_customers_type(string $a, string $b): void
    {
        $this->assertSame(AutoRuleMatcher::normalize($b), AutoRuleMatcher::normalize($a));
    }

    public function test_normalisation_keeps_arabic_letters_whole(): void
    {
        // trim() بقائمة محارف يقطع بايتات «ن»: لا يحدث هنا
        $this->assertSame('عمان', AutoRuleMatcher::normalize('عمّان؟'));
        $this->assertTrue(mb_check_encoding(AutoRuleMatcher::normalize('من؟ ن'), 'UTF-8'));
    }

    public function test_patterns_are_normalised_on_save(): void
    {
        $rule = $this->rule(['patterns' => ['/الموقع', 'موقعكم؟']]);

        $this->assertSame(['الموقع', 'موقعكم'], $rule->fresh()->patterns_norm);
        $this->assertSame(['/الموقع', 'موقعكم؟'], $rule->fresh()->patterns);
    }

    public function test_exact_needs_the_whole_text(): void
    {
        $rule = $this->rule(['match_type' => 'exact', 'patterns' => ['وين موقعكم']]);

        $this->assertTrue(AutoRuleMatcher::match($rule, AutoRuleMatcher::normalize('وين موقعكم؟')));
        $this->assertFalse(AutoRuleMatcher::match($rule, AutoRuleMatcher::normalize('وين موقعكم بالزبط')));
        $this->assertTrue(AutoRuleMatcher::match($this->rule(['match_type' => 'exact', 'patterns' => ['الموقع']]), AutoRuleMatcher::normalize('/الموقع')));
    }

    public function test_contains_needs_every_value_as_a_whole_word(): void
    {
        $rule = $this->rule(['match_type' => 'contains', 'patterns' => ['وثائق', 'التأشيرة']]);

        $this->assertTrue(AutoRuleMatcher::match($rule, AutoRuleMatcher::normalize('شو وثائق التأشيرة؟')));
        $this->assertFalse(AutoRuleMatcher::match($rule, AutoRuleMatcher::normalize('شو الوثائق المطلوبة')));
    }

    public function test_any_of_needs_one_whole_word_never_part_of_one(): void
    {
        $rule = $this->rule(['patterns' => ['دوام']]);

        $this->assertTrue(AutoRuleMatcher::match($rule, AutoRuleMatcher::normalize('متى دوام المكتب')));
        $this->assertFalse(AutoRuleMatcher::match($rule, AutoRuleMatcher::normalize('الدوامه')));
        $this->assertFalse(AutoRuleMatcher::match($rule, AutoRuleMatcher::normalize('دوامكم')));
    }

    public function test_a_broken_rule_is_skipped_alone_and_flagged_once_a_day(): void
    {
        $this->captureLogs();
        $broken = $this->rule(['priority' => 1]);
        DB::table('wa_auto_rules')->where('id', $broken->id)->update(['patterns_norm' => json_encode([null])]);
        $regex = $this->rule(['priority' => 2]);
        DB::table('wa_auto_rules')->where('id', $regex->id)->update(['match_type' => 'regex']);
        $good = $this->rule(['priority' => 3]);

        foreach (range(1, 10) as $_) {
            $hit = AutoRuleMatcher::firstMatch(WaAutoRule::active()->get(), $this->ctx('وين موقعكم'), $reasons);
            $this->assertSame($good->id, $hit['rule']->id);
        }

        $this->assertSame([$broken->id => 'invalid', $regex->id => 'invalid', $good->id => 'match'], $reasons);
        $this->assertStringContainsString('قيمة فارغة', $broken->fresh()->last_error);
        $this->assertStringContainsString('نوع مطابقة', $regex->fresh()->last_error);
        $this->assertNull($good->fresh()->last_error);
        $this->assertSame(2, collect($this->logged)->where('message', 'auto_rule_invalid')->count(), 'سطر واحد في اليوم لكل قاعدة');
        $this->assertLogged('error', 'auto_rule_invalid', ['rule' => $broken->id]);
    }

    public function test_editing_the_content_clears_the_error_and_the_preview(): void
    {
        $rule = $this->rule();
        $rule->forceFill(['last_error' => 'قاعدة تالفة: x', 'validated_at' => now()])->saveQuietly();

        $rule->update(['name' => 'اسم آخر']);
        $this->assertNotNull($rule->fresh()->validated_at, 'الاسم ليس محتوى');

        $rule->update(['patterns' => ['العنوان']]);
        $this->assertNull($rule->fresh()->validated_at);
        $this->assertNull($rule->fresh()->last_error);
    }

    public function test_conditions_are_all_required(): void
    {
        $base = ['conditions' => ['linked' => 'yes', 'topics_any' => ['visa']]];
        $rule = $this->rule($base);

        $this->assertTrue(AutoRuleMatcher::passesConditions($rule, $this->ctx('الموقع', [['text'], true, ['visa', 'umrah']])));
        $this->assertFalse(AutoRuleMatcher::passesConditions($rule, $this->ctx('الموقع', [['text'], false, ['visa']])));
        $this->assertFalse(AutoRuleMatcher::passesConditions($rule, $this->ctx('الموقع', [['text'], true, ['umrah']])));

        $unlinked = $this->rule(['conditions' => ['linked' => 'no']]);
        $this->assertFalse(AutoRuleMatcher::passesConditions($unlinked, $this->ctx('الموقع', [['text'], true])));
        $this->assertTrue(AutoRuleMatcher::passesConditions($unlinked, $this->ctx('الموقع', [['text'], false])));
    }

    public function test_voice_is_left_out_by_default_and_can_be_allowed(): void
    {
        $rule = $this->rule();
        $this->assertFalse(AutoRuleMatcher::passesConditions($rule, $this->ctx('الموقع', [['audio']])));
        $this->assertTrue(AutoRuleMatcher::passesConditions($rule, $this->ctx('الموقع', [['interactive']])));

        $voice = $this->rule(['conditions' => ['msg_types' => ['text', 'audio']]]);
        $this->assertTrue(AutoRuleMatcher::passesConditions($voice, $this->ctx('الموقع', [['audio']])));
    }

    public function test_referral_conditions_read_the_ad_that_brought_the_message(): void
    {
        $fromAds = $this->rule(['conditions' => ['has_referral' => true]]);
        $oneAd = $this->rule(['conditions' => ['referral_source_ids' => ['120000001']]]);
        $ad = [['source_type' => 'ad', 'source_id' => '120000001']];

        $this->assertTrue(AutoRuleMatcher::passesConditions($fromAds, $this->ctx('الموقع', [['text'], false, [], $ad])));
        $this->assertFalse(AutoRuleMatcher::passesConditions($fromAds, $this->ctx('الموقع')));
        $this->assertTrue(AutoRuleMatcher::passesConditions($oneAd, $this->ctx('الموقع', [['text'], false, [], $ad])));
        $this->assertFalse(AutoRuleMatcher::passesConditions($oneAd, $this->ctx('الموقع', [['text'], false, [], [['source_id' => '9']]])));
    }

    public function test_staff_numbers_are_excluded_unless_the_rule_says_otherwise(): void
    {
        $this->assertFalse(AutoRuleMatcher::passesConditions($this->rule(), $this->ctx('الموقع', [['text'], false, [], [], true])));
        $this->assertTrue(AutoRuleMatcher::passesConditions($this->rule(['conditions' => ['exclude_staff' => false]]), $this->ctx('الموقع', [['text'], false, [], [], true])));
    }

    public function test_a_phoneless_conversation_is_evaluated_without_errors(): void
    {
        $conv = WaConversation::factory()->windowOpen()->create(['phone' => null, 'wa_phone' => null, 'wa_user_id' => 'JO.1A2B3C4D5E6F']);
        $msg = WaMessage::factory()->inbound()->create(['conversation_id' => $conv->id, 'body' => 'وين موقعكم؟']);

        $ctx = AutoRuleContext::forConversation($conv, collect([$msg]));
        $this->assertFalse($ctx->linked);
        $this->assertSame('match', AutoRuleMatcher::evaluate($this->rule(), $ctx));

        $staff = WaConversation::factory()->windowOpen()->create(['user_id' => User::factory()->create()->id]);
        $this->assertSame('conditions', AutoRuleMatcher::evaluate($this->rule(), AutoRuleContext::forConversation($staff, collect([$msg]))));
    }

    public function test_a_message_longer_than_the_rule_allows_is_too_long(): void
    {
        $rule = $this->rule(['max_words' => 6]);

        $this->assertSame('match', AutoRuleMatcher::evaluate($rule, $this->ctx('ممكن اعرف وين موقعكم لو سمحت')));
        $this->assertSame('too_long', AutoRuleMatcher::evaluate($rule, $this->ctx('مرحبا ممكن اعرف وين موقعكم بالضبط لاني جاي بكرا')));
        $this->assertSame('no_match', AutoRuleMatcher::evaluate($rule, $this->ctx('كم سعر العمرة')));
    }
}
