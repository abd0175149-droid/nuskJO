<?php

namespace Tests\Feature\Security;

use App\Exceptions\ApprovalConflict;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payroll;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * انتقالات الحالة ذرية: نسختان من المستند نفسه (طلبان متزامنان) لا تعتمدانه مرتين،
 * والمنشئ لا يعتمد مستنده إلا المدير، وقيد فعّال واحد لكل مرجع.
 */
class AtomicApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creator = User::factory()->create();
    }

    private function invoice(string $status = 'pending'): Invoice
    {
        $id = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-T-'.uniqid(), 'client_id' => Client::factory()->create()->id,
            'exchange_rate_snapshot' => 0.19, 'invoice_date' => '2026-10-14', 'created_by' => $this->creator->id,
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Invoice::findOrFail($id);
    }

    private function receipt(): Receipt
    {
        $id = DB::table('receipts')->insertGetId([
            'receipt_number' => 'RCP-T-'.uniqid(), 'client_id' => Client::factory()->create()->id, 'amount_jod' => 10,
            'payment_method' => 'cash', 'receipt_date' => '2026-10-14', 'created_by' => $this->creator->id,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Receipt::findOrFail($id);
    }

    public function test_two_concurrent_approvals_only_one_wins(): void
    {
        $approver = User::factory()->admin()->create();
        $first = $this->invoice();
        $second = Invoice::findOrFail($first->id);   // قُرئت قبل أن يكتب الأول

        $first->approve($approver);

        $this->expectException(ApprovalConflict::class);
        $second->approve($approver);
    }

    public function test_reject_after_approve_and_double_start_edit_conflict(): void
    {
        $admin = User::factory()->admin()->create();
        $a = $this->receipt();
        $b = Receipt::findOrFail($a->id);
        $a->approve($admin);

        try {
            $b->reject($admin, 'متأخر');
            $this->fail('الرفض بعد الاعتماد يجب أن يتعارض');
        } catch (ApprovalConflict) {
        }
        $this->assertSame('approved', $a->fresh()->status);

        $c = Receipt::findOrFail($a->id);
        $d = Receipt::findOrFail($a->id);
        $c->startEditing($admin);
        $this->expectException(ApprovalConflict::class);
        $d->startEditing($admin);
    }

    public function test_resubmit_only_from_editing(): void
    {
        $admin = User::factory()->admin()->create();
        $r = $this->receipt();
        $r->approve($admin);
        $r->startEditing($admin);
        $r->resubmitForApproval();
        $this->assertSame('pending', $r->fresh()->status);

        $this->expectException(ApprovalConflict::class);
        $r->resubmitForApproval();
    }

    public function test_payroll_approval_is_atomic(): void
    {
        $id = DB::table('payrolls')->insertGetId(['payroll_number' => 'PR-T-1', 'month' => 9, 'year' => 2026, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $admin = User::factory()->admin()->create();
        $p1 = Payroll::findOrFail($id);
        $p2 = Payroll::findOrFail($id);

        $p1->approve($admin);
        $this->expectException(ApprovalConflict::class);
        $p2->approve($admin);
    }

    public function test_creator_cannot_approve_their_own_document_unless_admin(): void
    {
        $invoice = $this->invoice();
        $this->creator->forceFill(['role_id' => User::factory()->withPermissions(['invoices.approve'], 'approver')->create()->role_id])->save();

        $this->actingAs($this->creator->fresh())->post("/invoices/{$invoice->id}/approve")
            ->assertSessionHas('error', 'لا يعتمد المنشئ مستنده إلا المدير');
        $this->assertSame('pending', $invoice->fresh()->status);

        $this->assertTrue($invoice->approvableBy(User::factory()->withPermissions(['invoices.approve'], 'approver2')->create()));
        $admin = User::factory()->admin()->create();
        $ownByAdmin = $this->invoice();
        $ownByAdmin->forceFill(['created_by' => $admin->id])->save();
        $this->assertTrue($ownByAdmin->approvableBy($admin));
    }

    public function test_second_approval_request_gets_a_clear_message_not_a_failure_alert(): void
    {
        $admin = User::factory()->admin()->create();
        $invoice = $this->invoice('approved');

        $this->actingAs($admin)->post("/invoices/{$invoice->id}/approve")->assertSessionHas('error');
        $this->assertSame(0, DB::table('notifications')->where('title', 'like', '%فشل%')->count());
    }

    public function test_only_one_active_journal_entry_per_reference(): void
    {
        $row = ['entry_number' => 'JE-1', 'entry_date' => '2026-10-14', 'description' => 'x', 'reference_type' => 'invoice', 'reference_id' => 7,
            'is_reversed' => 0, 'created_by' => $this->creator->id, 'created_at' => now(), 'updated_at' => now()];
        $columns = array_column(DB::select('PRAGMA table_info(journal_entries)'), 'name');
        $row = array_intersect_key($row, array_flip($columns));

        DB::table('journal_entries')->insert($row);
        DB::table('journal_entries')->insert(['entry_number' => 'JE-2', 'is_reversed' => 1] + $row);   // المعكوس لا يُحتسب

        $this->expectException(QueryException::class);
        DB::table('journal_entries')->insert(['entry_number' => 'JE-3'] + $row);
    }
}
