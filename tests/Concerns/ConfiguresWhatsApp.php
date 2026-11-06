<?php

namespace Tests\Concerns;

use App\Models\WaBotSetting;
use Illuminate\Support\Facades\Http;

/**
 * تهيئة قناة واتساب ونموذج وهميين للاختبار، وتزييف البوابة والنماذج.
 * منذ 2026-11-06 القناة رقم جاهز على بوابة OpenWA وهمية (FakesOpenWa)، وfakeGraph يزيّف البوابة بالمعرّفات نفسها
 * (wamid.OUT.n) فتبقى اختبارات السجل كما هي. ثوابت ميتا باقية لاختبارات الإعدادات القديمة وحدها.
 */
trait ConfiguresWhatsApp
{
    use FakesOpenWa;

    protected const TEST_PHONE_NUMBER_ID = '106540352242922';

    protected const TEST_APP_SECRET = 'test-app-secret';

    protected const TEST_VERIFY_TOKEN = 'test-verify';

    protected function configureChannel(array $overrides = []): WaBotSetting
    {
        $s = WaBotSetting::current();
        $s->fill(array_merge([
            'wa_token' => 'test-token',
            'wa_phone_number_id' => self::TEST_PHONE_NUMBER_ID,
            'wa_app_secret' => self::TEST_APP_SECRET,
            'wa_verify_token' => self::TEST_VERIFY_TOKEN,
            'provider' => 'google',
            'model' => 'gemini-test',
            'api_key' => 'test-llm-key',
            'enabled' => true,
            'wa_suspended' => false,
        ], $overrides))->save();
        if (\App\Models\WaChannel::count() === 0) {
            $this->makeChannel();
        }
        \App\Models\WaChannel::forgetCache();

        return $s->fresh();
    }

    /**
     * يزيّف Graph: كل رسالة صادرة تعيد wamid.OUT.n.
     * $overrides: نمط => رد (يغلب الافتراضي)، مثل ['graph.facebook.com/*' => Http::response([...], 400)].
     */
    protected function fakeGraph(array $overrides = []): void
    {
        $this->fakeGateway(array_filter($overrides, fn ($k) => str_contains((string) $k, 'nuskjo-openwa'), ARRAY_FILTER_USE_KEY));
        $n = 0;
        Http::fake(array_merge([
            'graph.facebook.com/*' => function () use (&$n) {
                $n++;

                return Http::response([
                    'messaging_product' => 'whatsapp',
                    'contacts' => [['input' => '16505551234', 'wa_id' => '16505551234']],
                    'messages' => [['id' => 'wamid.OUT.'.$n]],
                ]);
            },
            'lookaside.fbsbx.com/*' => Http::response('MEDIA-BYTES', 200, ['Content-Type' => 'image/jpeg']),
        ], $overrides));
    }

    /**
     * ردود Gemini بالترتيب. كل عنصر نص (رد نصي) أو مصفوفة ['call' => name, 'args' => [...]] (استدعاء أداة).
     * يمكن جمعها مع fakeGraph لأن Http::fake يُراكم الأنماط.
     */
    protected function fakeGemini(string|array ...$turns): void
    {
        $sequence = Http::sequence();
        foreach ($turns as $turn) {
            $sequence->push($this->geminiResponse($turn));
        }

        Http::fake(['generativelanguage.googleapis.com/*' => $sequence]);
    }

    protected function geminiResponse(string|array $turn): array
    {
        if (is_string($turn)) {
            $json = $this->llmFixture('gemini_text.json');
            $json['candidates'][0]['content']['parts'][0]['text'] = $turn;

            return $json;
        }

        $json = $this->llmFixture('gemini_function_call.json');
        $json['candidates'][0]['content']['parts'][0]['functionCall'] = [
            'name' => $turn['call'],
            'args' => (object) ($turn['args'] ?? []),
        ];

        return $json;
    }

    protected function fakeAnthropic(string ...$texts): void
    {
        $sequence = Http::sequence();
        foreach ($texts as $text) {
            $json = $this->llmFixture('anthropic_text.json');
            $json['content'][0]['text'] = $text;
            $sequence->push($json);
        }

        Http::fake(['api.anthropic.com/*' => $sequence]);
    }

    protected function llmFixture(string $name): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/llm/'.$name)), true);
    }
}
