<?php

namespace Tests\Feature\WhatsApp\Media;

use App\Models\WaMessage;
use App\Support\FeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** أساس الأرشيف (E14.2): قرص خاص بلا رابط، وعلمان مطفآن، وهجرة إضافية متكررة الأمان، ومسار الملف لا يُسلسل */
class MediaArchiveSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_disk_is_private_and_outside_the_served_root(): void
    {
        $disk = config('filesystems.disks.'.config('whatsapp.media.disk'));

        $this->assertFalse($disk['serve']);
        $this->assertSame(storage_path('app/wa-media'), $disk['root']);
        $this->assertStringStartsNotWith(config('filesystems.disks.local.root'), $disk['root']);
        $this->assertSame([0600, 0700], [$disk['permissions']['file']['private'], $disk['permissions']['dir']['private']]);
    }

    public function test_both_flags_ship_off(): void
    {
        $this->assertFalse(FeatureFlags::on('media_archive'));
        $this->assertFalse(FeatureFlags::on('media_consent_prompt'));
        $this->assertNotContains('image/svg+xml', array_keys(config('whatsapp.media.mimes')));
        $this->assertNotContains('text/html', array_keys(config('whatsapp.media.mimes')));
    }

    public function test_the_migration_is_additive_idempotent_and_grants_the_admin_only(): void
    {
        DB::table('roles')->insertOrIgnore([['name' => 'مدير', 'slug' => 'admin'], ['name' => 'مبيعات', 'slug' => 'sales']]);
        $migration = require database_path('migrations/2026_10_14_000015_wa_media_archive.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('wa_messages', ['media_status', 'media_error', 'media_archived_at', 'media_path']));
        $this->assertTrue(Schema::hasColumns('wa_conversations', ['media_consent', 'media_consent_at', 'media_notice_at']));
        $id = DB::table('permissions')->where('slug', 'whatsapp.media')->sole()->id;
        $this->assertSame(['admin'], DB::table('role_permissions')->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('permission_id', $id)->pluck('roles.slug')->all());
    }

    public function test_the_stored_path_and_hash_never_serialize(): void
    {
        $m = WaMessage::factory()->inbound()->create(['media_path' => '2026/10/x.jpg', 'media_sha256' => str_repeat('a', 64), 'media_status' => 'stored']);

        $this->assertArrayNotHasKey('media_path', $m->toArray());
        $this->assertArrayNotHasKey('media_sha256', $m->toArray());
        $this->assertSame('stored', $m->toArray()['media_status']);
    }

    public function test_the_entrypoint_keeps_the_folder_closed(): void
    {
        $sh = file_get_contents(base_path('docker/entrypoint.sh'));

        $this->assertStringContainsString('find storage/app/wa-media -type d -exec chmod 700', $sh);
        $this->assertStringContainsString('find storage/app/wa-media -type f -exec chmod 600', $sh);
        $this->assertDoesNotMatchRegularExpression('/chmod -R \d+ storage(\s|$)/m', $sh, 'لا chmod شامل على storage يفتح الوثائق');
    }
}
