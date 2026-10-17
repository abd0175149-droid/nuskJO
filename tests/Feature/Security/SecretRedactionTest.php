<?php

namespace Tests\Feature\Security;

use App\Logging\RedactSecrets;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\WaLog;
use App\Support\SecretRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Monolog\Formatter\JsonFormatter;
use RuntimeException;
use Tests\TestCase;

/** لا أسرار في السجلات: معاملات الروابط، وترويسة Bearer، والقيم السرية المعروفة من الإعداد والقاعدة. */
class SecretRedactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SecretRedactor::forget();
    }

    public function test_url_parameters_and_bearer_tokens_are_masked(): void
    {
        $this->assertSame(
            'GET https://x.test/v1/models/m:generateContent?key=*** failed; https://graph.test/me?access_token=***&appsecret_proof=***&fields=id',
            SecretRedactor::clean('GET https://x.test/v1/models/m:generateContent?key=AIzaSyFAKE-123 failed; https://graph.test/me?access_token=EAAB123&appsecret_proof=abc&fields=id'),
        );
        $this->assertSame('Authorization: Bearer ***', SecretRedactor::clean('Authorization: Bearer EAAGm0PX4ZCpsBA'));
    }

    public function test_known_secrets_from_config_and_database_are_masked_wherever_they_appear(): void
    {
        config(['services.llm.google_key' => 'env-google-key-123', 'services.whatsapp.token' => null]);
        WaBotSetting::current()->update(['wa_token' => 'db-wa-token-456', 'api_key' => 'db-llm-key-789', 'wa_app_secret' => 'short']);

        $this->assertSame('a *** b *** c *** d short', SecretRedactor::clean('a env-google-key-123 b db-wa-token-456 c db-llm-key-789 d short'));
    }

    public function test_a_secret_saved_later_is_masked_from_the_next_record(): void
    {
        $this->assertSame('x new-token-0001', SecretRedactor::clean('x new-token-0001'));

        WaBotSetting::current()->update(['wa_token' => 'new-token-0001']);

        $this->assertSame('x ***', SecretRedactor::clean('x new-token-0001'));
    }

    public function test_never_throws_when_settings_cannot_be_read(): void
    {
        Schema::drop('wa_bot_settings');

        $this->assertSame('plain ?key=***', SecretRedactor::clean('plain ?key=abc'));
    }

    public function test_log_channels_write_redacted_messages_context_and_exceptions(): void
    {
        foreach (['single', 'daily', 'stderr'] as $channel) {
            $this->assertContains(RedactSecrets::class, config("logging.channels.{$channel}.tap", []), $channel);
        }

        $path = storage_path('logs/redaction-test-'.bin2hex(random_bytes(4)).'.log');
        config(['logging.channels.redaction_test' => [
            'driver' => 'single', 'path' => $path, 'tap' => [RedactSecrets::class],
        ]]);
        WaBotSetting::current()->update(['api_key' => 'db-llm-key-789']);

        try {
            Log::channel('redaction_test')->error('call failed ?key=AIzaSyFAKE-123', [
                'url' => 'https://graph.test/x?access_token=EAAB123',
                'nested' => ['header' => 'Bearer EAAGm0PX4ZCpsBA'],
                'exception' => new RuntimeException('cURL error 6 for https://x.test/m?key=db-llm-key-789'),
            ]);
            $written = File::get($path);
        } finally {
            File::delete($path);
        }

        foreach (['AIzaSyFAKE-123', 'EAAB123', 'EAAGm0PX4ZCpsBA', 'db-llm-key-789'] as $secret) {
            $this->assertStringNotContainsString($secret, $written);
        }
        $this->assertStringContainsString('RuntimeException(code: 0): cURL error 6', $written);
    }

    public function test_known_secret_shapes_are_masked_by_value(): void
    {
        $key = 'AIza'.str_repeat('B', 35);
        $text = "g={$key} a=sk-ant-api03-abcdefghijklmnop m=EAAGm0PX4ZCpsBAabcdefghij app=base64:".str_repeat('A', 43).'= '
            ."/webhook?hub.mode=subscribe&hub.verify_token=letmein&client_secret=s3cr3t\n-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----";

        $clean = SecretRedactor::clean($text);

        foreach ([$key, 'sk-ant-api03', 'EAAGm0PX4ZCpsBA', str_repeat('A', 43), 'letmein', 's3cr3t', 'MIIE'] as $secret) {
            $this->assertStringNotContainsString($secret, $clean);
        }
        $this->assertStringContainsString('[PRIVATE KEY]', $clean);
    }

    public function test_secret_keys_are_masked_by_name_but_token_counts_are_kept(): void
    {
        $this->assertSame(
            ['password' => '***', 'wa_app_secret' => '***', 'remember_token' => '***', 'nested' => ['api_key' => '***', 'Authorization' => '***'],
                'prompt_tokens' => 120, 'tokens_out' => 9, 'note' => 'ok'],
            SecretRedactor::cleanArray(['password' => 'p', 'wa_app_secret' => 's', 'remember_token' => 'r', 'nested' => ['api_key' => 'k', 'Authorization' => 'Bearer x'],
                'prompt_tokens' => 120, 'tokens_out' => 9, 'note' => 'ok']),
        );
    }

    public function test_whatsapp_log_keeps_only_allowed_keys_and_the_phone_tail(): void
    {
        $this->assertSame(
            ['conv_id' => 7, 'phone_tail' => '1234', 'meta_code' => 131047],
            WaLog::filter(['conv_id' => 7, 'phone_tail' => '+962 79 000 1234', 'meta_code' => 131047, 'body' => 'نص العميل', 'name' => 'اسم', 'phone' => '962790001234']),
        );
    }

    public function test_whatsapp_channel_writes_json_lines(): void
    {
        $this->assertSame(['wa_json', 'single'], config('logging.channels.whatsapp.channels'));
        $this->assertSame(JsonFormatter::class, config('logging.channels.wa_json.formatter'));
        $this->assertContains(RedactSecrets::class, config('logging.channels.wa_json.tap'));
    }
}
