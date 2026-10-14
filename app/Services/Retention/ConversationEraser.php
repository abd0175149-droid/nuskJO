<?php

namespace App\Services\Retention;

use App\Models\WaConversation;
use App\Services\WhatsApp\PhoneNormalizer;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * حذف بيانات محادثة على دفعات (E13.5): كل دفعة 500 صف في معاملة مستقلة مع توقف قصير، فيبقى الويبهوك والبوت
 * يعملان (SQLite كاتب واحد). كل خطوة متكررة الأمان، فالتنفيذ المنقطع يُستأنف بإعادة التشغيل.
 * forget: بطلب صاحبها (ومعه حذف الوسائط الحديثة من ميتا). retention: التقليم (بلا ميتا، ولا يمس العميل).
 * ما يرتبط بفاتورة يبقى سجلاً مالياً. صف المحادثة يصير «شاهداً» بلا أي بيان شخصي.
 */
final class ConversationEraser
{
    /** وسائط الوارد عند ميتا تنتهي بعد أيام: حذفها لما هو أحدث من هذا وحده */
    private const MEDIA_DAYS = 7;

    private const MEDIA_TYPES = ['image', 'video', 'audio', 'document', 'sticker'];

    public function plan(WaConversation $conv, string $mode = 'forget'): ErasurePlan
    {
        $ids = array_values(array_unique(array_merge([$conv->id],
            Schema::hasColumn('wa_conversations', 'merged_into_id') ? WaConversation::where('merged_into_id', $conv->id)->pluck('id')->all() : [])));
        $canonical = $conv->phone ? (PhoneNormalizer::canonical($conv->phone) ?: null) : null;

        $quotes = DB::table('quote_requests')->whereIn('conversation_id', $ids);
        $kept = (clone $quotes)->where(fn ($q) => $q->whereNotNull('invoice_id')->orWhereExists($this->invoiceForQuote()))->count();

        $counts = [
            'messages' => DB::table('wa_messages')->whereIn('conversation_id', $ids)->count(),
            'notes' => DB::table('wa_customer_notes')->whereIn('conversation_id', $ids)->count(),
            'topics' => Schema::hasTable('wa_conversation_topics') ? DB::table('wa_conversation_topics')->whereIn('conversation_id', $ids)->count() : 0,
            'quotes' => (clone $quotes)->count() - $kept,
            'quotes_kept_invoiced' => $kept,
            'notifications' => $this->notificationsQuery($ids, $canonical)->count(),
            'jobs' => $this->jobsQuery('jobs', $ids, $canonical)->count() + $this->jobsQuery('failed_jobs', $ids, $canonical)->count(),
            'admin_actions_redacted' => $canonical ? $this->adminActionsQuery($canonical)->count() : 0,
            'usage_detached' => DB::table('wa_bot_usage')->whereIn('conversation_id', $ids)->count(),
            'files' => Schema::hasColumn('wa_messages', 'media_path') ? DB::table('wa_messages')->whereIn('conversation_id', $ids)->whereNotNull('media_path')->where('media_path', '!=', '')->count() : 0,
        ];
        $media = $mode === 'forget' ? $this->recentMediaIds($ids) : [];
        $counts['media_meta'] = count($media);

        return new ErasurePlan($conv->id, $ids, $mode, $canonical, $counts, $media);
    }

    /** @return array<string, int> ما حُذف فعلاً */
    public function execute(ErasurePlan $plan, ?callable $progress = null): array
    {
        $ids = $plan->conversationIds;
        $done = ['media_meta_deleted' => 0, 'media_meta_failed' => 0];
        $step = fn (string $name, int $n) => $progress ? $progress($name, $n) : null;

        // 1. الوسائط عند ميتا (forget وحده): فشل النداء لا يوقف الحذف
        foreach ($plan->mediaIds as $mediaId) {
            WhatsAppChannel::deleteMedia($mediaId) ? $done['media_meta_deleted']++ : $done['media_meta_failed']++;
            usleep(100_000);
        }
        // 2. الملفات المؤرشفة (قرص E14) إن وُجد
        $done['files'] = $this->deleteArchivedFiles($ids);

        // 3–6. الرسائل والملاحظات والوسوم وطلبات التسعير غير المرتبطة بفاتورة
        $done['messages'] = $this->deleteInBatches('wa_messages', fn ($q) => $q->whereIn('conversation_id', $ids));
        $step('messages', $done['messages']);
        $done['notes'] = $this->deleteInBatches('wa_customer_notes', fn ($q) => $q->whereIn('conversation_id', $ids));
        $done['topics'] = Schema::hasTable('wa_conversation_topics') ? $this->deleteInBatches('wa_conversation_topics', fn ($q) => $q->whereIn('conversation_id', $ids)) : 0;
        $quoteNumbers = DB::table('quote_requests')->whereIn('conversation_id', $ids)->whereNull('invoice_id')->whereNotExists($this->invoiceForQuote())->pluck('request_number')->filter()->all();
        $done['quotes'] = $this->deleteInBatches('quote_requests', fn ($q) => $q->whereIn('conversation_id', $ids)->whereNull('invoice_id')->whereNotExists($this->invoiceForQuote()));
        $done['quotes_kept_invoiced'] = DB::table('quote_requests')->whereIn('conversation_id', $ids)->count();

        // 7–9. الإشعارات والوظائف والكاش
        $done['notifications'] = $this->deleteInBatches('notifications', fn ($q) => $this->notificationsWhere($q, $ids, $plan->canonicalPhone, $quoteNumbers));
        $done['jobs'] = $this->deleteInBatches('jobs', fn ($q) => $this->jobsWhere($q, $ids, $plan->canonicalPhone))
            + $this->deleteInBatches('failed_jobs', fn ($q) => $this->jobsWhere($q, $ids, $plan->canonicalPhone));
        $done['cache'] = array_sum(array_map(fn ($id) => ConversationCacheKeys::forget($id), $ids));

        // 10–11. حجب بحث المساعد الإداري بالرقم، وفصل الكلفة عن الشخص
        $done['admin_actions_redacted'] = $plan->canonicalPhone
            ? $this->adminActionsQuery($plan->canonicalPhone)->update(['input' => json_encode(['redacted' => 'erasure'])]) : 0;
        $done['usage_detached'] = DB::table('wa_bot_usage')->whereIn('conversation_id', $ids)->update(['conversation_id' => null]);

        // 12. المحادثة (والمدموجة فيها) صفوف شاهدة بلا بيانات شخصية
        $this->witness($ids, $plan->mode === 'retention' ? 'pruned' : 'erased');

        return $done;
    }

    /** المحادثة شاهداً: لا رقم ولا اسم ولا معرّف ولا مقتطف. رسالة لاحقة من الرقم تنشئ محادثة جديدة نظيفة */
    private function witness(array $ids, string $status): void
    {
        $columns = ['phone' => null, 'wa_phone' => null, 'display_name' => null, 'client_id' => null, 'last_message_preview' => null,
            'status' => $status, 'bot_enabled' => false, 'needs_attention' => false, 'unread_count' => 0, 'assigned_to' => null];
        foreach (['wa_user_id', 'wa_username', 'wa_profile_name', 'referral', 'client_link', 'attention_reason', 'client_linked_by'] as $c) {
            if (Schema::hasColumn('wa_conversations', $c)) {
                $columns[$c] = null;
            }
        }
        if (Schema::hasColumn('wa_conversations', 'erased_at')) {
            $columns['erased_at'] = now();
        }
        DB::table('wa_conversations')->whereIn('id', $ids)->update($columns);
    }

    private function deleteInBatches(string $table, callable $where): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }
        $size = max(1, (int) config('retention.batch_size', 500));
        $sleep = (int) config('retention.batch_sleep_ms', 50);
        $total = 0;
        while (true) {
            $ids = $where(DB::table($table))->orderBy('id')->limit($size)->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            DB::transaction(fn () => DB::table($table)->whereIn('id', $ids)->delete());
            $total += count($ids);
            if (count($ids) < $size) {
                break;
            }
            $sleep > 0 && usleep($sleep * 1000);
        }

        return $total;
    }

    private function invoiceForQuote(): \Closure
    {
        return fn ($q) => $q->selectRaw('1')->from('invoices')->whereColumn('invoices.quote_request_id', 'quote_requests.id');
    }

    /** أشكال الرقم كما قد يظهر في نص: الموحَّد، و+، والمحلي الأردني 07… */
    private function phoneForms(?string $canonical): array
    {
        if (!$canonical) {
            return [];
        }
        $forms = [$canonical];
        if (str_starts_with($canonical, '962') && strlen($canonical) === 12) {
            $forms[] = '0'.substr($canonical, 3);
        }

        return $forms;
    }

    private function notificationsQuery(array $ids, ?string $canonical)
    {
        return $this->notificationsWhere(DB::table('notifications'), $ids, $canonical, []);
    }

    private function notificationsWhere($q, array $ids, ?string $canonical, array $quoteNumbers)
    {
        return $q->where(function ($w) use ($ids, $canonical, $quoteNumbers) {
            foreach ($ids as $id) {
                $w->orWhere('action_url', 'like', "%conv={$id}")->orWhere('action_url', 'like', "%conv={$id}&%");
            }
            foreach ($this->phoneForms($canonical) as $form) {
                $w->orWhere('body', 'like', "%{$form}%")->orWhere('title', 'like', "%{$form}%");
            }
            foreach ($quoteNumbers as $number) {
                $w->orWhere('body', 'like', "%{$number}%");
            }
        });
    }

    private function jobsQuery(string $table, array $ids, ?string $canonical)
    {
        return Schema::hasTable($table) ? $this->jobsWhere(DB::table($table), $ids, $canonical) : DB::table('wa_messages')->whereRaw('1 = 0');
    }

    private function jobsWhere($q, array $ids, ?string $canonical)
    {
        return $q->where(function ($w) use ($ids, $canonical) {
            foreach ($ids as $id) {
                $w->orWhere('payload', 'like', '%conversationId\\";i:'.$id.';%')->orWhere('payload', 'like', '%conversationId";i:'.$id.';%');
            }
            foreach ($this->phoneForms($canonical) as $form) {
                $w->orWhere('payload', 'like', "%{$form}%");
            }
        });
    }

    private function adminActionsQuery(string $canonical)
    {
        return DB::table('wa_admin_actions')->where(function ($w) use ($canonical) {
            foreach ($this->phoneForms($canonical) as $form) {
                $w->orWhere('input', 'like', "%{$form}%");
            }
            // بحث find_client بآخر 9 أرقام
            $w->orWhere('input', 'like', '%'.substr($canonical, -9).'%');
        });
    }

    /**
     * «احذف ملفاتي» (E14.10): المرفقات وحدها، والمحادثة ورسائلها باقية. الملفات من القرص، والحديث منها عند ميتا،
     * وما ينتظر الأرشفة لا يُحفظ بعدها (skipped_consent).
     *
     * @return array{files: int, media_meta_deleted: int, media_meta_failed: int}
     */
    public function eraseMedia(WaConversation $conv): array
    {
        $ids = array_values(array_unique(array_merge([$conv->id],
            Schema::hasColumn('wa_conversations', 'merged_into_id') ? WaConversation::where('merged_into_id', $conv->id)->pluck('id')->all() : [])));
        $done = ['files' => $this->deleteArchivedFiles($ids), 'media_meta_deleted' => 0, 'media_meta_failed' => 0];
        if (Schema::hasColumn('wa_messages', 'media_status')) {
            DB::table('wa_messages')->whereIn('conversation_id', $ids)->whereNotNull('media_path')->update(['media_status' => 'purged', 'media_path' => null, 'updated_at' => now()]);
            DB::table('wa_messages')->whereIn('conversation_id', $ids)->whereIn('media_status', ['pending', 'failed', 'too_large'])->update(['media_status' => 'skipped_consent', 'updated_at' => now()]);
        }
        foreach ($this->recentMediaIds($ids) as $mediaId) {
            WhatsAppChannel::deleteMedia($mediaId) ? $done['media_meta_deleted']++ : $done['media_meta_failed']++;
        }

        return $done;
    }

    /** @return list<string> */
    private function recentMediaIds(array $ids): array
    {
        $rows = DB::table('wa_messages')->whereIn('conversation_id', $ids)->where('direction', 'in')
            ->whereIn('msg_type', self::MEDIA_TYPES)->where('created_at', '>=', now()->subDays(self::MEDIA_DAYS))
            ->get(Schema::hasColumn('wa_messages', 'media_id') ? ['msg_type', 'payload', 'media_id'] : ['msg_type', 'payload']);

        return $rows->map(fn ($m) => ($m->media_id ?? null) ?: data_get(json_decode((string) $m->payload, true), "{$m->msg_type}.id"))
            ->filter(fn ($id) => is_string($id) && $id !== '')->unique()->values()->all();
    }

    private function deleteArchivedFiles(array $ids): int
    {
        $disk = config('whatsapp.media.disk', 'wa_media');
        if (!Schema::hasColumn('wa_messages', 'media_path') || !config("filesystems.disks.{$disk}")) {
            return 0;
        }
        $deleted = 0;
        DB::table('wa_messages')->whereIn('conversation_id', $ids)->whereNotNull('media_path')->where('media_path', '!=', '')
            ->orderBy('id')->chunk(500, function ($rows) use ($disk, &$deleted) {
                foreach ($rows as $m) {
                    $deleted += Storage::disk($disk)->delete($m->media_path) ? 1 : 0;
                }
            });

        return $deleted;
    }
}
