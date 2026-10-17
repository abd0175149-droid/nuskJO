<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Models\WaCustomerNote;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/**
 * تصدير محادثة واحدة (E30.6): بصلاحية whatsapp.export وسبب مكتوب، عبر السياج، وصف تدقيق warn قبل البث وفشله يمنعه،
 * وCSV محمي من حقن الصيغ، وTXT بلا حمولة خام ولا ملاحظات داخلية، واسم ملف بلا هاتف، و5 في الدقيقة.
 */
class ConversationExportTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    private User $exporter;

    private WaConversation $conv;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.conversation_export' => true]);
        $this->exporter = $this->staffWith('exporter', ['whatsapp.view', 'whatsapp.view_all', 'whatsapp.export']);
        $this->conv = $this->conversationTagged([], ['phone' => '16505551234', 'wa_phone' => '16505551234', 'display_name' => 'سامي']);
        $staff = User::factory()->create(['name' => 'ليلى']);
        WaMessage::factory()->create(['conversation_id' => $this->conv->id, 'body' => "مرحباً\nأريد عمرة", 'payload' => ['secret_raw' => 'RAWJSON'],
            'created_at' => '2026-10-01 07:00:00']);
        WaMessage::factory()->outbound()->create(['conversation_id' => $this->conv->id, 'body' => 'أهلاً بك', 'created_at' => '2026-10-01 07:01:00']);
        WaMessage::factory()->fromStaff($staff)->create(['conversation_id' => $this->conv->id, 'body' => '=1+1', 'created_at' => '2026-10-02 09:00:00']);
        WaMessage::factory()->fromStaff($staff)->create(['conversation_id' => $this->conv->id, 'body' => 'لم تصل', 'status' => 'failed', 'created_at' => '2026-10-02 09:05:00']);
        WaMessage::factory()->create(['conversation_id' => $this->conv->id, 'msg_type' => 'image', 'body' => null, 'created_at' => '2026-10-03 10:00:00']);
        WaCustomerNote::create(['conversation_id' => $this->conv->id, 'note' => 'ملاحظة داخلية سرية', 'source' => 'staff', 'created_at' => now()]);
    }

    private function export(array $params = [], ?User $user = null, ?WaConversation $c = null)
    {
        $params += ['format' => 'txt', 'reason' => 'طلب العميل نسخة لنزاع الدفع'];

        return $this->actingAs($user ?? $this->exporter)
            ->get('/api/whatsapp/conversations/'.($c ?? $this->conv)->id.'/export?'.http_build_query($params), ['Accept' => 'application/json']);
    }

    public function test_txt_has_a_bom_a_header_and_one_line_per_message_without_internal_data(): void
    {
        $response = $this->export()->assertOk();
        $body = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString("محادثة واتساب #{$this->conv->id} — سامي", $body);
        $this->assertStringContainsString('الرقم: +16505551234', $body);
        $this->assertStringContainsString('بواسطة '.$this->exporter->name, $body);
        $this->assertStringContainsString("[2026-10-01 10:00] العميل: مرحباً\n    أريد عمرة", $body, 'الوقت بتوقيت الأردن والأسطر التالية بإزاحة');
        $this->assertStringContainsString('] البوت: أهلاً بك', $body);
        $this->assertStringContainsString('] موظف: ليلى: لم تصل ⟨لم تُرسل⟩', $body);
        $this->assertStringContainsString('] العميل: 📷 صورة', $body);
        $this->assertStringNotContainsString('RAWJSON', $body);
        $this->assertStringNotContainsString('ملاحظة داخلية', $body);

        $this->assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertMatchesRegularExpression('/^attachment; filename=wa-conv-'.$this->conv->id.'-\d{8}-\d{6}\.txt$/', $disposition);
        $this->assertStringNotContainsString('16505551234', $disposition);
        $this->assertStringNotContainsString('سامي', $disposition);
    }

    public function test_csv_neutralises_formulas_and_keeps_its_columns(): void
    {
        $body = $this->export(['format' => 'csv'])->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $h = fopen('php://memory', 'w+');
        fwrite($h, substr($body, 3));
        rewind($h);
        $lines = [];
        while (($row = fgetcsv($h, null, ',', '"', '')) !== false) {
            $lines[] = $row;
        }

        $this->assertSame(['message_id', 'at', 'direction', 'source', 'sender', 'type', 'status', 'body'], $lines[0]);
        $this->assertSame("'=1+1", $lines[3][7]);
        $this->assertSame('موظف: ليلى', $lines[3][4]);
        $this->assertSame('2026-10-02T12:00:00+03:00', $lines[3][1]);
        $this->assertCount(6, $lines, 'ترويسة وخمس رسائل');
    }

    public function test_a_strict_warn_audit_row_is_written_before_streaming(): void
    {
        $this->export(['format' => 'csv', 'from' => '2026-10-02', 'to' => '2026-10-03'])->assertOk();

        $row = AuditLog::where('action', AuditAction::WaConversationExport->value)->sole();
        $this->assertSame(['warn', 'wa_conversation', $this->conv->id, $this->exporter->id], [$row->severity, $row->entity_type, (int) $row->entity_id, $row->user_id]);
        $this->assertSame(['format' => 'csv', 'from' => '2026-10-02', 'to' => '2026-10-03', 'rows_estimate' => 3, 'reason' => 'طلب العميل نسخة لنزاع الدفع'], $row->changes);
    }

    public function test_a_failed_audit_write_blocks_the_export(): void
    {
        AuditLog::creating(fn () => throw new \RuntimeException('audit store down'));
        $this->withoutExceptionHandling();

        try {
            $this->export();
            $this->fail('صُدّرت المحادثة بلا صف تدقيق');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit store down', $e->getMessage());
        }
    }

    public function test_permission_flag_reason_and_period_are_enforced(): void
    {
        $viewer = $this->staffWith('viewer', ['whatsapp.view', 'whatsapp.view_all']);
        $this->export([], $viewer)->assertForbidden();

        $this->export(['reason' => ''])->assertStatus(422)->assertJsonValidationErrors(['reason' => 'اكتب سبب التصدير']);
        $this->export(['reason' => 'لا'])->assertStatus(422);
        $this->export(['format' => 'pdf'])->assertStatus(422);
        $this->export(['from' => '2026-10-05', 'to' => '2026-10-01'])->assertStatus(422);

        config(['whatsapp.export.max_rows' => 4]);
        $this->export()->assertStatus(422)->assertJsonPath('rows', 5);
        $this->assertSame(0, AuditLog::where('action', AuditAction::WaConversationExport->value)->count());

        $this->travel(61)->seconds();
        config(['features.env.conversation_export' => false]);
        $this->export()->assertNotFound();
    }

    public function test_the_fence_applies_including_admin_chats(): void
    {
        $specialist = $this->staffWith('umrah-desk', ['whatsapp.view', 'whatsapp.export'], ['umrah']);
        $this->export([], $specialist)->assertNotFound();

        WaAdminNumber::factory()->create(['phone' => '16505553333']);
        $adminChat = $this->conversationTagged([], ['phone' => '16505553333', 'wa_phone' => '16505553333']);
        $this->export([], null, $adminChat)->assertNotFound();

        $owner = $this->staffWith('owner', ['whatsapp.view', 'whatsapp.view_all', 'whatsapp.admin_chats', 'whatsapp.export']);
        $this->export([], $owner, $adminChat)->assertOk();
    }

    public function test_five_exports_a_minute(): void
    {
        foreach (range(1, 5) as $i) {
            $this->export()->assertOk();
        }
        $this->export()->assertStatus(429);
        $this->assertSame(5, AuditLog::where('action', AuditAction::WaConversationExport->value)->count());
    }

    public function test_the_permission_migration_grants_admin_only_and_is_repeatable(): void
    {
        $migration = require database_path('migrations/2026_10_14_000030_whatsapp_export_permission.php');
        $migration->up();
        $migration->up();

        $id = DB::table('permissions')->where('slug', 'whatsapp.export')->value('id');
        $roles = DB::table('role_permissions')->join('roles', 'roles.id', '=', 'role_permissions.role_id')->where('permission_id', $id)->pluck('roles.slug')->all();
        $this->assertSame(1, DB::table('permissions')->where('slug', 'whatsapp.export')->count());
        $this->assertNotContains('sales', $roles);
        $this->assertContains('whatsapp.export', Permission::SENSITIVE);
    }

    public function test_the_inbox_offers_the_button_only_with_permission_and_flag(): void
    {
        $this->actingAs($this->exporter)->get('/whatsapp/inbox')->assertInertia(fn ($p) => $p->where('canExport', true));
        $viewer = $this->staffWith('viewer', ['whatsapp.view']);
        $this->actingAs($viewer)->get('/whatsapp/inbox')->assertInertia(fn ($p) => $p->where('canExport', false));
        config(['features.env.conversation_export' => false]);
        $this->actingAs($this->exporter)->get('/whatsapp/inbox')->assertInertia(fn ($p) => $p->where('canExport', false));
    }
}
