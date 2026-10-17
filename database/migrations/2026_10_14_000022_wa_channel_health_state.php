<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Support\AuditActor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * صحة القناة كآلة حالات (E22): أعمدة wa_health_* في wa_bot_settings، وجدول wa_account_events لأحداث حساب ميتا
 * ببصمة فريدة. إضافات محروسة بلا ->change() ولا إعادة بناء.
 *
 * ترحيل واحد في معاملة يفصل القفل الآلي عن اليدوي دون رفع أي قفل: wa_suspended=1 بمصدر meta_event أو graph_error
 * (كتبه حدث حساب أو رمز ميتا) يصير wa_health=BLOCKED بمصدر legacy ثم wa_suspended=0. والبوابة تمنع BLOCKED دائماً،
 * فالإرسال ممنوع قبل الترحيل وبعده. اليدوي والفارغ (قديم مجهول) وrestore تبقى في wa_suspended كما هي.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wa_bot_settings')) {
            $columns = [
                'wa_health' => fn (Blueprint $t) => $t->string('wa_health', 16)->default('UNKNOWN'),
                'wa_health_reason' => fn (Blueprint $t) => $t->string('wa_health_reason', 255)->nullable(),
                'wa_health_source' => fn (Blueprint $t) => $t->string('wa_health_source', 16)->nullable(),
                'wa_health_until' => fn (Blueprint $t) => $t->timestamp('wa_health_until')->nullable(),
                'wa_health_checked_at' => fn (Blueprint $t) => $t->timestamp('wa_health_checked_at')->nullable(),
                'wa_health_changed_at' => fn (Blueprint $t) => $t->timestamp('wa_health_changed_at')->nullable(),
                'wa_health_unblock_suggested' => fn (Blueprint $t) => $t->boolean('wa_health_unblock_suggested')->default(false),
                'wa_health_meta' => fn (Blueprint $t) => $t->json('wa_health_meta')->nullable(),
            ];
            foreach ($columns as $column => $add) {
                if (!Schema::hasColumn('wa_bot_settings', $column)) {
                    Schema::table('wa_bot_settings', $add);
                }
            }
        }

        if (!Schema::hasTable('wa_account_events')) {
            Schema::create('wa_account_events', function (Blueprint $t) {
                $t->id();
                $t->string('field', 48);
                // event أو alert_type أو decision
                $t->string('event', 64)->nullable();
                // معرّف حساب الأعمال من entry.id
                $t->string('entry_id', 32)->nullable();
                $t->string('phone_number_id', 64)->nullable();
                // critical | warn | info
                $t->string('severity', 10);
                // none | alert | poll | limited | blocked | unblock_suggested | ignored_foreign
                $t->string('effect', 24);
                $t->string('fingerprint', 64)->unique();
                $t->timestamp('occurred_at')->nullable();
                // value الخام بعد SecretRedactor، 16KB حداً أقصى. null لحدث حساب أو رقم آخر (بيانات جهة أخرى)
                $t->json('payload')->nullable();
                $t->timestamp('handled_at')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['field', 'created_at']);
            });
        }

        $this->moveAutomaticLock();
    }

    /** القفل الآلي القديم إلى BLOCKED (legacy). مشروط بالحالة نفسها، فالتشغيل الثاني لا يجد ما ينقله */
    private function moveAutomaticLock(): void
    {
        if (!Schema::hasColumns('wa_bot_settings', ['wa_suspended', 'wa_suspended_source', 'wa_health'])) {
            return;
        }

        $moved = DB::transaction(function () {
            $row = DB::table('wa_bot_settings')->where('wa_suspended', true)->whereIn('wa_suspended_source', ['meta_event', 'graph_error'])->first();
            if ($row === null) {
                return null;
            }
            $update = [
                'wa_health' => 'BLOCKED',
                'wa_health_source' => 'legacy',
                'wa_health_reason' => mb_substr('legacy:'.$row->wa_suspended_source.' — قفل آلي سابق: '.$row->wa_suspended_reason, 0, 255),
                'wa_health_changed_at' => now(),
                'wa_suspended' => false,
                'wa_suspended_source' => null,
            ];
            if (Schema::hasColumn('wa_bot_settings', 'settings_version')) {
                $update['settings_version'] = DB::raw('settings_version + 1');
            }
            $affected = DB::table('wa_bot_settings')->where('id', $row->id)->where('wa_suspended', true)
                ->whereIn('wa_suspended_source', ['meta_event', 'graph_error'])->update($update);

            return $affected > 0 ? $row : null;
        });

        if ($moved !== null) {
            Log::warning('wa.health.legacy_lock_moved', ['source' => $moved->wa_suspended_source]);
            try {
                AuditActor::as('system', 'migration:2026_10_14_000022', fn () => AuditLog::safe(AuditAction::WaHealthChange, 'wa_channel', null, 'BLOCKED', [
                    'from' => 'SUSPENDED_AUTO', 'to' => 'BLOCKED', 'source' => 'legacy', 'reason_code' => (string) $moved->wa_suspended_source,
                ]));
            } catch (Throwable) {
                // التدقيق لا يُسقط الهجرة؛ سطر السجل أعلاه يكفي للأثر
            }
        }
    }

    /** الإضافات وحدها. وحجب قائم يعود قفلاً آلياً في wa_suspended قبل حذف عموده: التراجع لا يرفع قفلاً */
    public function down(): void
    {
        if (Schema::hasColumns('wa_bot_settings', ['wa_suspended', 'wa_suspended_source', 'wa_health'])) {
            DB::table('wa_bot_settings')->where('wa_health', 'BLOCKED')->where('wa_suspended', false)->update([
                'wa_suspended' => true, 'wa_suspended_source' => 'meta_event', 'wa_suspended_at' => now(),
                'wa_suspended_reason' => DB::raw("substr(coalesce(wa_health_reason, 'BLOCKED'), 1, 255)"),
            ]);
        }
        Schema::dropIfExists('wa_account_events');
        foreach (['wa_health', 'wa_health_reason', 'wa_health_source', 'wa_health_until', 'wa_health_checked_at', 'wa_health_changed_at', 'wa_health_unblock_suggested', 'wa_health_meta'] as $column) {
            if (Schema::hasColumn('wa_bot_settings', $column)) {
                Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
