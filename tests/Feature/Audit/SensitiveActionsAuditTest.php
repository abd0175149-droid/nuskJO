<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Offer;
use App\Models\Permission;
use App\Models\QuoteRequest;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * الأفعال الحساسة تترك صفاً (E09): المال صارم داخل المعاملة، والصلاحيات بقيمها قبل وبعد، والأسعار بفرقها، ولا أسرار.
 */
class SensitiveActionsAuditTest extends TestCase
{
    use RefreshDatabase;

    private function receipt(User $creator): Receipt
    {
        $id = DB::table('receipts')->insertGetId([
            'receipt_number' => 'RCP-T-'.uniqid(), 'client_id' => Client::factory()->create()->id, 'amount_jod' => 25,
            'payment_method' => 'cash', 'receipt_date' => '2026-10-14', 'created_by' => $creator->id,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Receipt::findOrFail($id);
    }

    private function row(AuditAction $action): AuditLog
    {
        return AuditLog::where('action', $action->value)->sole();
    }

    public function test_rejecting_a_receipt_writes_a_row_with_the_request_context(): void
    {
        $admin = User::factory()->admin()->create();
        $receipt = $this->receipt(User::factory()->create());

        $this->actingAs($admin)->post("/receipts/{$receipt->id}/reject", ['rejection_reason' => 'مكرر'])->assertRedirect();

        $row = $this->row(AuditAction::Reject);
        $this->assertSame('receipt', $row->entity_type);
        $this->assertSame($receipt->id, $row->entity_id);
        $this->assertSame(25.0, (float) $row->changes['amount_jod']);
        $this->assertSame('user', $row->actor_type);
        $this->assertSame($admin->id, $row->user_id);
        $this->assertSame('POST', $row->method);
        $this->assertSame('warn', $row->severity);
    }

    public function test_a_money_action_is_rolled_back_when_its_audit_row_cannot_be_written(): void
    {
        $admin = User::factory()->admin()->create();
        $receipt = $this->receipt(User::factory()->create());
        Schema::drop('audit_logs');

        $this->actingAs($admin)->post("/receipts/{$receipt->id}/reject", ['rejection_reason' => 'مكرر'])->assertServerError();

        $this->assertSame('pending', $receipt->fresh()->status, 'لا رفض بلا صف تدقيق');
    }

    public function test_a_role_change_records_both_roles_and_a_password_reset_records_no_hash(): void
    {
        $admin = User::factory()->admin()->create();
        $sales = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'مبيعات']);
        $accountant = Role::firstOrCreate(['slug' => 'accountant'], ['name' => 'محاسب']);
        $user = User::factory()->create(['role_id' => $sales->id]);

        $this->actingAs($admin)->put("/users/{$user->id}", [
            'name' => $user->name, 'email' => $user->email, 'role_id' => $accountant->id,
            'password' => 'NewPassw0rd!x', 'password_confirmation' => 'NewPassw0rd!x',
        ])->assertSessionHasNoErrors();

        $change = $this->row(AuditAction::UserRoleChange);
        $this->assertSame(['sales', 'accountant'], [$change->changes['from_role'], $change->changes['to_role']]);
        $this->assertSame($user->id, $change->entity_id);

        $reset = $this->row(AuditAction::UserPasswordReset);
        $this->assertSame($user->id, $reset->entity_id);
        $dump = json_encode(AuditLog::all()->toArray());
        $this->assertStringNotContainsString('NewPassw0rd', $dump);
        $this->assertStringNotContainsString('$2y$', $dump);
    }

    public function test_a_role_update_lists_added_and_removed_permission_slugs(): void
    {
        $admin = User::factory()->admin()->create();
        $role = Role::create(['slug' => 'clerk', 'name' => 'موظف']);
        $view = Permission::firstOrCreate(['slug' => 'receipts.view'], ['name' => 'v', 'module' => 'receipts']);
        $approve = Permission::firstOrCreate(['slug' => 'receipts.approve'], ['name' => 'a', 'module' => 'receipts']);
        $role->permissions()->sync([$view->id]);

        $this->actingAs($admin)->put("/roles/{$role->id}", ['name' => 'موظف', 'permissions' => [$approve->id]])
            ->assertSessionHasNoErrors();

        $row = $this->row(AuditAction::RoleUpdate);
        $this->assertSame(['receipts.approve'], $row->changes['added']);
        $this->assertSame(['receipts.view'], $row->changes['removed']);
    }

    public function test_an_offer_price_change_records_before_and_after(): void
    {
        $user = User::factory()->withPermissions(['offers.update'])->create();
        $offer = Offer::factory()->bare()->create(['category' => 'visa', 'title' => 'تأشيرة', 'price_per_person' => 40]);

        $this->actingAs($user)->put("/offers/{$offer->id}", ['title' => 'تأشيرة', 'category' => 'visa', 'price_per_person' => 55])
            ->assertSessionHasNoErrors();

        $row = $this->row(AuditAction::OfferPriceChange);
        $this->assertSame(['before' => 40, 'after' => 55], array_map('intval', $row->changes['changed']['يبدأ من']));
        $this->assertSame(1, AuditLog::where('action', AuditAction::Update->value)->where('entity_type', 'offer')->count());
    }

    public function test_pricing_a_quote_and_setting_an_exchange_rate_are_recorded(): void
    {
        $user = User::factory()->withPermissions(['quotes.price', 'settings.update'])->create();
        $quote = QuoteRequest::factory()->create();

        $this->actingAs($user)->post("/quote-requests/{$quote->id}/price", ['quoted_price_jod' => 310, 'send_to_client' => false])
            ->assertSessionHasNoErrors();
        $price = $this->row(AuditAction::QuotePrice);
        $this->assertSame(310.0, (float) $price->changes['quoted_price_jod']);
        $this->assertFalse($price->changes['send_to_client']);
        $this->assertStringNotContainsString($quote->phone, json_encode($price->toArray()));

        $this->actingAs($user)->post('/settings/exchange-rate', ['rate_date' => '2026-10-14', 'sar_to_jod' => 0.189]);
        $this->actingAs($user)->post('/settings/exchange-rate', ['rate_date' => '2026-10-14', 'sar_to_jod' => 0.19]);
        $rates = AuditLog::where('action', AuditAction::ExchangeRateSet->value)->orderBy('id')->get();
        $this->assertCount(2, $rates);
        $this->assertNull($rates[0]->changes['from']);
        $this->assertSame([0.189, 0.19], [(float) $rates[1]->changes['from'], (float) $rates[1]->changes['to']]);
    }
}
