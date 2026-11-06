<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaConversation;
use App\Support\AuditActor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * محادثات ميتا القديمة بعد الانتقال إلى OpenWA (2026-11-06): ما بلا رقم واتساب (channel_id فارغ) لا يُرد عليه ولا يُرسل منه.
 * بلا --confirm يعدّ فقط. مع --confirm يحذفها ورسائلها وما يتبعها في معاملة واحدة، بعد نسخة احتياطية يأخذها المالك بنفسه.
 * يبقى ما له قيمة محاسبية أو أمنية: طلبات التسعير والاستهلاك والحظر وطلبات المحو تُفصل عن المحادثة (conversation_id = null).
 */
class WhatsAppPurgeLegacy extends Command
{
    protected $signature = 'whatsapp:purge-legacy {--confirm : احذف فعلاً (بعد نسخة احتياطية)}';

    protected $description = 'حذف محادثات ميتا القديمة (بلا رقم واتساب) ورسائلها بعد الانتقال إلى OpenWA';

    /** جداول تُحذف صفوفها مع المحادثة */
    private const OWNED = [
        'wa_messages', 'wa_customer_notes', 'wa_conversation_topics', 'wa_conversation_labels', 'wa_thread_pins',
        'wa_conversation_pins', 'wa_choice_sets', 'wa_outbox',
    ];

    /** جداول تبقى صفوفها وتُفصل عن المحادثة */
    private const DETACHED = ['quote_requests', 'wa_bot_usage', 'wa_blocks', 'wa_erasure_requests', 'wa_admin_actions'];

    public function handle(): int
    {
        $ids = WaConversation::query()->whereNull('channel_id')->pluck('id')->all();
        $counts = ['conversations' => count($ids)];
        foreach ([...self::OWNED, ...self::DETACHED] as $table) {
            $counts[$table] = $this->has($table) ? $this->countFor($table, $ids) : null;
        }
        $this->table(['الجدول', 'الصفوف'], collect($counts)->map(fn ($n, $t) => [$t.(in_array($t, self::DETACHED, true) ? ' (يُفصل ويبقى)' : ''), $n ?? '—'])->values()->all());

        if ($ids === []) {
            $this->info('✅ لا محادثات قديمة بلا رقم');

            return self::SUCCESS;
        }
        if (!$this->option('confirm')) {
            $this->warn('لم يُحذف شيء. خذ نسخة احتياطية أولاً (php artisan backup:run)، ثم أعد الأمر مع --confirm.');

            return self::SUCCESS;
        }

        $done = AuditActor::as('system', 'artisan:whatsapp:purge-legacy', fn () => DB::transaction(function () use ($ids) {
            $done = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (self::DETACHED as $table) {
                    if ($this->has($table)) {
                        $done[$table] = ($done[$table] ?? 0) + DB::table($table)->whereIn('conversation_id', $chunk)->update(['conversation_id' => null]);
                    }
                }
                foreach (self::OWNED as $table) {
                    if ($this->has($table)) {
                        $done[$table] = ($done[$table] ?? 0) + DB::table($table)->whereIn('conversation_id', $chunk)->delete();
                    }
                }
                $done['conversations'] = ($done['conversations'] ?? 0) + DB::table('wa_conversations')->whereIn('id', $chunk)->delete();
            }
            AuditLog::log(AuditAction::RetentionPruned, 'retention', null, 'legacy_meta_conversations', $done);

            return $done;
        }));
        rescue(fn () => \App\Services\WhatsApp\Search\FtsSchema::afterBulkDelete(), null, false);

        $this->info('🗑️ حُذفت '.$done['conversations'].' محادثة قديمة و'.($done['wa_messages'] ?? 0).' رسالة.');

        return self::SUCCESS;
    }

    private function has(string $table): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, 'conversation_id');
    }

    private function countFor(string $table, array $ids): int
    {
        $n = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $n += DB::table($table)->whereIn('conversation_id', $chunk)->count();
        }

        return $n;
    }
}
