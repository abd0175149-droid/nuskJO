<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\WhatsApp\ChannelDoctor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * كوكي الجلسة الآمن صراحةً (E27): Secure حين يكون الموقع https، ولا يمس التطوير المحلي على http، والفحص ينبّه في
 * الإنتاج إن غاب. وسياسة كلمة المرور الواحدة قابلة للرفع بقرار المالك دون كود.
 */
class SessionCookieAndPasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** يقرأ config/session.php ببيئة مؤقتة (null = غير مضبوط)، ثم يعيد البيئة كما كانت */
    private function sessionConfigWith(?string $appUrl, ?string $secure): mixed
    {
        $saved = [];
        foreach (['APP_URL' => $appUrl, 'SESSION_SECURE_COOKIE' => $secure] as $key => $value) {
            $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            $this->setEnv($key, $value, $value);
        }
        try {
            return (require config_path('session.php'))['secure'];
        } finally {
            foreach ($saved as $key => [$server, $env]) {
                $this->setEnv($key, $server, $env);
            }
        }
    }

    private function setEnv(string $key, ?string $server, ?string $env): void
    {
        if ($server === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $server;
        }
        if ($env === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $env;
        }
    }

    public function test_the_cookie_is_secure_for_an_https_site_and_untouched_for_local_http(): void
    {
        $this->assertTrue($this->sessionConfigWith('https://nuskjo.example.test', null));
        $this->assertNull($this->sessionConfigWith('http://localhost', null));
        $this->assertFalse($this->sessionConfigWith('https://nuskjo.example.test', 'false'), 'تعطيل صريح للطوارئ');
        $this->assertTrue($this->sessionConfigWith('http://localhost', 'true'));
    }

    public function test_the_session_cookie_carries_the_secure_flag_when_configured(): void
    {
        config(['session.secure' => true]);
        $cookie = collect($this->get('/login')->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_the_doctor_warns_in_production_when_the_cookie_is_not_secure(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['session.secure' => null]);
        $this->assertContains('SESSION_COOKIE_NOT_SECURE', array_column(ChannelDoctor::checks(), 'code'));

        config(['session.secure' => true]);
        $this->assertNotContains('SESSION_COOKIE_NOT_SECURE', array_column(ChannelDoctor::checks(), 'code'));
    }

    public function test_the_password_policy_is_raised_by_configuration_alone(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password-1')]);
        $change = fn (string $new) => $this->actingAs($user)->from('/profile')->put('/profile/password', [
            'current_password' => 'old-password-1', 'password' => $new, 'password_confirmation' => $new,
        ]);

        config(['security.password.min' => 10, 'security.password.letters_and_numbers' => true]);
        $change('abcdefghij')->assertSessionHasErrors('password');
        $change('abc1')->assertSessionHasErrors('password');
        $change('abcdefghi1')->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('abcdefghi1', $user->fresh()->password));
    }
}
