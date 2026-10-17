<?php

namespace Tests\Feature\WhatsApp;

use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\BuildsWhatsAppStaff;
use Tests\TestCase;

/** whatsapp:fence-report: أعداد لكل موظف بعد السياج، بلا أسماء ولا هواتف، ولا يغيّر شيئاً. */
class FenceReportTest extends TestCase
{
    use BuildsWhatsAppStaff;
    use RefreshDatabase;

    public function test_reports_counts_per_user_without_personal_data(): void
    {
        $specialist = $this->staffWith('umrah-desk', ['whatsapp.view'], ['umrah']);
        $idle = $this->staffWith('idle-desk', ['whatsapp.view']);
        $manager = $this->staffWith('manager', ['whatsapp.view', 'whatsapp.view_all']);
        User::factory()->withPermissions(['whatsapp.view'], 'gone')->inactive()->create();

        $umrah = $this->conversationTagged(['umrah'], ['display_name' => 'عميل سري']);
        $this->conversationTagged([], ['assigned_to' => $specialist->id]);
        $this->conversationTagged();   // بلا وسم ولا توكيل
        $number = WaAdminNumber::factory()->create();
        WaConversation::factory()->create(['phone' => $number->phone, 'wa_phone' => $number->phone]);
        QuoteRequest::factory()->forConversation($umrah)->create();
        QuoteRequest::factory()->create(['status' => 'priced']);
        $before = WaConversation::orderBy('id')->get()->toArray();

        $out = new BufferedOutput;
        $this->assertSame(0, Artisan::call('whatsapp:fence-report', ['--json' => true], $out));
        $text = $out->fetch();
        $report = json_decode($text, true);

        $this->assertSame(['conversations' => 4, 'admin_chats' => 1, 'unrouted' => 2, 'open_quotes' => 2, 'fence_quotes' => false,
            'users_seeing_nothing' => [$idle->id]], $report['summary']);
        $rows = collect($report['users'])->keyBy('user');
        $this->assertCount(3, $rows, 'المعطّل لا يظهر');
        $this->assertSame(['visible' => 2, 'assigned' => 1, 'admin_hidden' => 1, 'quotes_fenced' => 1], array_intersect_key($rows[$specialist->id], array_flip(['visible', 'assigned', 'admin_hidden', 'quotes_fenced'])));
        $this->assertSame(3, $rows[$manager->id]['visible']);
        $this->assertSame(2, $rows[$manager->id]['quotes_fenced']);

        $this->assertStringNotContainsString('عميل سري', $text);
        $this->assertStringNotContainsString($number->phone, $text);
        $this->assertStringNotContainsString($specialist->name, $text);
        $this->assertSame($before, WaConversation::orderBy('id')->get()->toArray());
        $this->assertFalse(config('services.whatsapp.fence_quotes'), 'المفتاح أُعيد كما كان');
    }

    public function test_table_output_warns_about_staff_who_would_see_nothing(): void
    {
        $this->staffWith('idle-desk', ['whatsapp.view']);

        $this->artisan('whatsapp:fence-report')
            ->expectsOutputToContain('لا يرون أي محادثة')
            ->assertExitCode(0);
    }
}
