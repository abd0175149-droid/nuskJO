<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use App\Models\WaErasureRequest;
use App\Services\Retention\ConversationEraser;
use App\Services\Retention\ErasurePlan;
use App\Services\WhatsApp\ErasureRequests;
use App\Services\WhatsApp\PhoneNormalizer;
use App\Support\Retention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * حذف بيانات عميل بطلبه (E13.5). يُشغَّل دائماً بـ: docker exec -u www-data nuskjo-app php artisan whatsapp:forget …
 * بلا --token: معاينة بالأعداد ورمز تأكيد (15 دقيقة). مع --token: يعيد الحساب ثم ينفّذ على دفعات.
 * لا مسار HTTP يصل إلى هنا. يُكتب صف تدقيق وسطر في سجل المحو الخارجي بالأعداد وبصمة الرقم فقط.
 */
class WhatsAppForget extends Command
{
    protected $signature = 'whatsapp:forget {phone? : رقم العميل}
        {--conversation= : رقم المحادثة} {--request= : رقم طلب الحذف}
        {--client : امحُ بطاقة العميل أيضاً إن لم يكن له أثر مالي} {--include-admin : اسمح بمحادثة رقم إدارة}
        {--token= : رمز التأكيد من المعاينة} {--force-unverified : طلب لم يُتحقَّق من صاحبه (بقرار المالك)} {--json}';

    protected $description = 'حذف بيانات عميل بطلبه: معاينة ثم تنفيذ برمز تأكيد';

    public function handle(ConversationEraser $eraser): int
    {
        [$conv, $request] = $this->resolve();
        if (!$conv) {
            return self::FAILURE;
        }
        if ($conv->status === 'erased') {
            $this->error('المحادثة محذوفة مسبقاً.');

            return self::FAILURE;
        }
        if (($conv->is_admin_chat || ($conv->phone && WaAdminNumber::forPhone($conv->phone))) && !$this->option('include-admin')) {
            $this->error('محادثة رقم إدارة: أضف --include-admin إن كان هذا مقصوداً.');

            return self::FAILURE;
        }

        $plan = $eraser->plan($conv, 'forget');
        $clientCheck = $this->option('client') ? $this->clientCheck($conv) : null;

        if (!$this->option('token')) {
            return $this->preview($plan, $clientCheck);
        }

        $saved = Cache::store('file')->pull('wa:forget:token:'.strtoupper((string) $this->option('token')));
        if (!is_array($saved) || $saved['conv'] !== $conv->id) {
            $this->error('رمز التأكيد خاطئ أو منتهٍ — شغّل المعاينة من جديد.');

            return self::FAILURE;
        }
        if ($saved['fp'] !== $plan->fingerprint()) {
            $this->error('تغيّرت الأعداد بعد المعاينة (غير الرسائل الجديدة) — شغّل المعاينة من جديد.');

            return self::FAILURE;
        }

        return $this->run_($eraser, $plan, $conv, $request, $clientCheck);
    }

    /** @return array{0: ?WaConversation, 1: ?WaErasureRequest} */
    private function resolve(): array
    {
        $phone = $this->argument('phone') ? PhoneNormalizer::canonical((string) $this->argument('phone')) : null;
        $request = null;

        if ($id = $this->option('request')) {
            $request = WaErasureRequest::find($id);
            if (!$request) {
                $this->error('لا يوجد طلب بهذا الرقم.');

                return [null, null];
            }
            $allowed = $this->option('force-unverified') ? ['received', 'verified', 'running'] : ['verified', 'running'];
            if (!in_array($request->status, $allowed, true)) {
                $this->error("حالة الطلب {$request->status}: يُنفَّذ بعد «تحقّقت من الهوية» في صفحة الطلبات.");

                return [null, null];
            }
            if ($phone && !hash_equals($request->subject_hmac, Retention::subjectHmac($phone))) {
                $this->error('الرقم لا يطابق صاحب الطلب.');

                return [null, null];
            }
            $conv = $request->conversation_id ? WaConversation::find($request->conversation_id) : ($phone ? WaConversation::where('phone', $phone)->first() : null);
        } elseif ($id = $this->option('conversation')) {
            $conv = WaConversation::find($id);
        } elseif ($phone) {
            $conv = WaConversation::where('phone', $phone)->first();
        } else {
            $this->error('حدّد الرقم أو --conversation أو --request.');

            return [null, null];
        }

        if (!$conv) {
            $this->error('لا توجد محادثة مطابقة.');
        }

        return [$conv, $request];
    }

    private function preview(ErasurePlan $plan, ?array $clientCheck): int
    {
        $token = strtoupper(Str::random(6));
        Cache::store('file')->put("wa:forget:token:{$token}", ['conv' => $plan->conversationId, 'fp' => $plan->fingerprint()], now()->addMinutes(15));

        if ($this->option('json')) {
            $this->line(json_encode(['conversation_id' => $plan->conversationId, 'counts' => $plan->counts, 'client' => $clientCheck, 'token' => $token], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $this->table(['البند', 'العدد'], collect($plan->counts)->map(fn ($n, $k) => [$k, $n])->values()->all());
        if ($plan->counts['quotes_kept_invoiced'] > 0) {
            $this->warn("تبقى {$plan->counts['quotes_kept_invoiced']} طلبات تسعير مرتبطة بفواتير (سجل مالي).");
        }
        if ($clientCheck) {
            $clientCheck['ok'] ? $this->line('بطاقة العميل تُمحى (بلا أثر مالي).') : $this->warn('بطاقة العميل لا تُمحى: '.$clientCheck['reason']);
        }
        $this->info("للتنفيذ خلال 15 دقيقة: أعد الأمر نفسه مع --token={$token}");

        return self::SUCCESS;
    }

    private function run_(ConversationEraser $eraser, ErasurePlan $plan, WaConversation $conv, ?WaErasureRequest $request, ?array $clientCheck): int
    {
        $subject = ErasureRequests::subjectFor($conv);
        $userHmac = $conv->wa_user_id ? Retention::userIdHmac($conv->wa_user_id) : null;
        $request ??= WaErasureRequest::whereIn('status', ['verified', 'running'])->where('subject_hmac', $subject)->first()
            ?? WaErasureRequest::create(['status' => 'verified', 'channel' => 'staff', 'conversation_id' => $conv->id, 'subject_hmac' => (string) $subject,
                'wa_user_hmac' => $userHmac, 'received_at' => now(), 'due_at' => now()->addDays((int) config('retention.erasure_due_days', 30)),
                'verified_at' => now(), 'verification_method' => 'in_person']);
        $request->update(['status' => 'running']);

        $clientId = $conv->client_id;
        $counts = $eraser->execute($plan, fn ($step, $n) => $this->option('json') ? null : $this->line("• {$step}: {$n}"));
        if ($clientCheck && $clientCheck['ok'] && $clientId) {
            $counts['client_erased'] = $this->eraseClient($clientId) ? 1 : 0;
        }

        DB::transaction(function () use ($request, $counts) {
            $request->update(['status' => 'done', 'executed_at' => now(), 'counts' => $counts,
                'executed_by' => mb_substr((get_current_user() ?: 'cli').'@'.(gethostname() ?: 'host'), 0, 64)]);
            AuditLog::log(AuditAction::WaDataErase, 'wa_erasure_request', $request->id, null,
                ['counts' => $counts, 'mode' => 'forget', 'kept_invoiced_quotes' => $counts['quotes_kept_invoiced'] ?? 0]);
        });
        Retention::appendErasureLedger(['request_id' => $request->id, 'subject_hmac' => $request->subject_hmac, 'wa_user_hmac' => $request->wa_user_hmac,
            'done_at' => now()->toIso8601String(), 'counts' => $counts]);
        Retention::recordShrink(['wa_messages' => $counts['messages'] ?? 0, 'quote_requests' => $counts['quotes'] ?? 0]);

        $this->option('json') ? $this->line(json_encode(['request_id' => $request->id, 'counts' => $counts], JSON_UNESCAPED_UNICODE))
            : $this->info("✅ نُفّذ الطلب #{$request->id}. بقيت {$counts['quotes_kept_invoiced']} طلبات مرتبطة بفواتير، وصف «إيقاف» إن وُجد.");

        return self::SUCCESS;
    }

    /** بطاقة العميل تُمحى فقط إن لم يكن له أي أثر مالي (فاتورة، سند، قيد على حسابه) */
    private function clientCheck(WaConversation $conv): array
    {
        if (!$conv->client_id || !($client = Client::find($conv->client_id))) {
            return ['ok' => false, 'reason' => 'المحادثة غير مربوطة بعميل'];
        }
        $financial = DB::table('invoices')->where('client_id', $client->id)->exists()
            || DB::table('receipts')->where('client_id', $client->id)->exists()
            || ($client->account_id && DB::table('journal_entry_lines')->where('account_id', $client->account_id)->exists());

        return $financial ? ['ok' => false, 'reason' => 'له فواتير أو سندات أو قيود (سجل مالي يبقى)'] : ['ok' => true, 'reason' => null];
    }

    private function eraseClient(int $clientId): bool
    {
        $client = Client::find($clientId);
        if (!$client) {
            return false;
        }
        $client->forceFill(['name' => "عميل محذوف #{$client->id}", 'phone' => null, 'email' => null, 'address' => null,
            'id_number' => null, 'notes' => null, 'contact_person' => null])->saveQuietly();
        $client->delete();

        return true;
    }
}
