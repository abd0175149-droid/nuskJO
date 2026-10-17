<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** قائمة سماح مفاتيح الإعدادات، وحذف الملفات المرفوعة بأمان، وحد محاولات الدخول. */
class SettingsAndLoginHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function seedSettings(): void
    {
        foreach (['company_name_ar' => 'نسك', 'company_email' => 'info@example.test', 'reconcile_signature' => 'sig-1', 'company_logo' => 'brand/logo.png', 'invoice_prefix' => 'INV'] as $key => $value) {
            Setting::create(['key' => $key, 'value' => $value, 'group_name' => 'company']);
        }
    }

    public function test_only_editable_keys_are_written_and_the_rest_is_audited(): void
    {
        $this->seedSettings();

        $this->actingAs(User::factory()->admin()->create())->put('/settings', ['settings' => [
            ['key' => 'company_name_ar', 'value' => 'نسك الذهبية'],
            ['key' => 'reconcile_signature', 'value' => 'forged'],
            ['key' => 'company_logo', 'value' => '../../.env'],
        ]])->assertSessionHasNoErrors();

        $this->assertSame('نسك الذهبية', Setting::get('company_name_ar'));
        $this->assertSame('sig-1', Setting::get('reconcile_signature'));
        $this->assertSame('brand/logo.png', Setting::get('company_logo'));
        $this->assertSame(['reconcile_signature', 'company_logo'], AuditLog::where('action', 'settings.dropped_keys')->sole()->changes['keys']);
    }

    public function test_values_are_validated_per_key(): void
    {
        $this->seedSettings();

        $this->actingAs(User::factory()->admin()->create())->put('/settings', ['settings' => [
            ['key' => 'company_email', 'value' => 'not-an-email'],
            ['key' => 'invoice_prefix', 'value' => 'IN V/'],
        ]])->assertSessionHasErrors(['values.company_email', 'values.invoice_prefix']);

        $this->assertSame('info@example.test', Setting::get('company_email'));
    }

    public function test_settings_page_hides_internal_keys_and_lists_editable_ones(): void
    {
        $this->seedSettings();

        $this->actingAs(User::factory()->admin()->create())->get('/settings')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('settings', fn ($groups) => !str_contains(json_encode($groups), 'reconcile_signature'))
                ->where('editableKeys', fn ($keys) => collect($keys)->contains('company_name_ar') && !collect($keys)->contains('company_logo')));
    }

    public function test_uploading_a_logo_never_deletes_a_file_outside_the_brand_folder(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('secret/keep.txt', 'x');
        Setting::create(['key' => 'company_logo', 'value' => 'secret/keep.txt', 'group_name' => 'company']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/settings/brand-asset', ['type' => 'logo', 'asset' => UploadedFile::fake()->image('logo.png')]);
        Storage::disk('public')->assertExists('secret/keep.txt');

        // ملفنا السابق في brand/ يُستبدل ويُحذف كالمعتاد
        $previous = Setting::get('company_logo');
        $this->assertStringStartsWith('brand/', $previous);
        $this->actingAs($admin)->post('/settings/brand-asset', ['type' => 'logo', 'asset' => UploadedFile::fake()->image('logo2.png')]);
        Storage::disk('public')->assertMissing($previous);
    }

    public function test_new_users_need_eight_character_passwords(): void
    {
        $sales = Role::factory()->create(['slug' => 'sales']);

        $this->actingAs(User::factory()->admin()->create())->post('/users', [
            'name' => 'موظف', 'email' => 'new@example.test', 'password' => 'short7x', 'role_id' => $sales->id,
        ])->assertSessionHasErrors('password');
    }

    public function test_login_is_throttled_per_email_and_ip_with_a_masked_audit_entry(): void
    {
        User::factory()->create(['email' => 'victim@example.test']);

        foreach (range(1, 5) as $_) {
            $this->post('/login', ['email' => 'victim@example.test', 'password' => 'wrong'])
                ->assertSessionHasErrors(['email' => 'بيانات الدخول غير صحيحة.']);
        }

        $this->post('/login', ['email' => 'victim@example.test', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'محاولات دخول كثيرة. انتظر دقيقة ثم أعد المحاولة.']);

        $entry = AuditLog::where('action', 'auth.lockout')->sole();
        $this->assertSame('v***@example.test', $entry->entity_label);

        // بريد آخر من العنوان نفسه لا يُقفل بسبب الأول
        $this->post('/login', ['email' => 'other@example.test', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'بيانات الدخول غير صحيحة.']);
    }
}
