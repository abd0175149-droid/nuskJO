<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Role;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** هجرة السياج متكررة الأمان: لا تكرّر الصلاحية، وتمنحها لدور admin، وتعلّم محادثات أرقام الإدارة الموجودة. */
class FenceMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_twice_without_duplicates_and_backfills_admin_chats(): void
    {
        DB::table('permissions')->where('slug', 'whatsapp.admin_chats')->delete();
        $admin = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'مدير']);
        $number = WaAdminNumber::factory()->create();
        $conversation = WaConversation::factory()->create(['phone' => $number->phone, 'wa_phone' => $number->phone]);
        $client = WaConversation::factory()->create();
        $this->assertFalse($conversation->fresh()->is_admin_chat);

        $migration = require database_path('migrations/2026_10_14_000005_whatsapp_conversation_fence.php');
        $migration->up();
        $migration->up();

        $permission = DB::table('permissions')->where('slug', 'whatsapp.admin_chats')->get();
        $this->assertCount(1, $permission);
        $this->assertSame(1, DB::table('role_permissions')->where('role_id', $admin->id)->where('permission_id', $permission[0]->id)->count());
        $this->assertTrue($conversation->fresh()->is_admin_chat);
        $this->assertFalse($client->fresh()->is_admin_chat);
    }
}
