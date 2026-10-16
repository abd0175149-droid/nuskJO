<?php

namespace App\Services\WhatsApp\Realtime;

use App\Events\WaConversationChanged;
use App\Models\WaConversation;
use App\Services\Ops\OpsHealth;
use App\Services\WhatsApp\WaLog;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\DB;

/**
 * البث اللحظي للصندوق (E28، العلم inbox_realtime). مسجّل scoped: يعيش طلباً واحداً أو وظيفة واحدة.
 *
 * - touch() يجمع لكل محادثة أعلى نوع (message ثم meta ثم status) واتحاد alsoNotify (من فقد المحادثة بالفعل نفسه).
 * - في طلب HTTP يُبث مرة واحدة بعد إرسال الرد (defer، ومنه رد 200 للويبهوك)، فلا يتأخر الرد ولا يُبث ما لم يُعتمد.
 * - في الطابور والطرفية (رد البوت مثلاً) يُبث بعد اعتماد المعاملة الجارية فوراً: الموظف ينتظر ظهور الرد.
 * - لا يرمي أبداً: كل البث داخل try/catch، وفشله يفتح قاطعاً 60 ثانية (rt:down في مخزن OpsHealth، file في الإنتاج)
 *   فلا يدفع كل حدث تالٍ مهلة الثانيتين، وسطر rt.broadcast_failed باسم الاستثناء وحده.
 */
final class InboxBroadcaster
{
    /** رتبة النوع: الأعلى يغلب في الدفعة */
    public const RANK = ['status' => 1, 'meta' => 2, 'message' => 3];

    public const BREAKER = 'rt:down';

    public const BREAKER_SECONDS = 60;

    /** @var array<int, array{k: string, also: list<int>}> */
    private array $pending = [];

    private bool $scheduled = false;

    private ?bool $enabled = null;

    /** العلم مقروءاً مرة لكل طلب أو وظيفة (كل حفظ لمحادثة يمر من هنا) */
    public function enabled(): bool
    {
        return $this->enabled ??= rescue(fn () => FeatureFlags::on('inbox_realtime'), false, false);
    }

    /** @param  list<int>  $alsoNotify  مستخدمون يُبلَّغون ولو لم يعودوا يرون المحادثة (فقدوها بهذا الفعل) */
    public function touch(int $conversationId, string $kind, array $alsoNotify = []): void
    {
        try {
            if ($conversationId <= 0 || !isset(self::RANK[$kind]) || !$this->enabled()) {
                return;
            }

            $current = $this->pending[$conversationId] ?? ['k' => $kind, 'also' => []];
            if (self::RANK[$kind] > self::RANK[$current['k']]) {
                $current['k'] = $kind;
            }
            $current['also'] = array_values(array_unique([...$current['also'], ...array_filter(array_map('intval', $alsoNotify))]));
            $this->pending[$conversationId] = $current;

            $this->schedule();
        } catch (\Throwable $e) {
            WaLog::error('rt.touch_failed', ['exception' => class_basename($e), 'conv_id' => $conversationId]);
        }
    }

    /** المعلّق في هذا الطلب (للاختبار والتشخيص) */
    public function pending(): array
    {
        return $this->pending;
    }

    private function schedule(): void
    {
        if (self::inHttpRequest()) {
            if (!$this->scheduled) {
                $this->scheduled = true;
                defer(fn () => $this->flush(), always: true);
            }

            return;
        }

        // بعد اعتماد المعاملة الجارية (فوراً إن لم تكن): الصف المقروء بعد الحدث موجود فعلاً، والتراجع لا يُبث
        DB::afterCommit(fn () => $this->flush());
    }

    /** طلب يمر بالموجّه (php-fpm، أو طلب اختبار)؛ عامل الطابور والأوامر بلا مسار */
    private static function inHttpRequest(): bool
    {
        return !app()->runningInConsole() || (app()->bound('request') && request()->route() !== null);
    }

    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        $this->scheduled = false;
        if ($pending === []) {
            return;
        }

        try {
            if (!FeatureFlags::on('inbox_realtime') || OpsHealth::store()->has(self::BREAKER)) {
                return;
            }

            $conversations = WaConversation::query()->whereKey(array_keys($pending))->get()->keyBy('id');
            $audience = InboxAudience::forMany($conversations);

            foreach ($pending as $id => $p) {
                if (!$conversations->has($id)) {
                    continue;
                }
                $ids = array_values(array_unique([...($audience[$id] ?? []), ...$p['also']]));
                if ($ids === []) {
                    continue;
                }

                try {
                    event(new WaConversationChanged($id, $p['k'], $ids));
                } catch (\Throwable $e) {
                    OpsHealth::store()->put(self::BREAKER, 1, self::BREAKER_SECONDS);
                    WaLog::warning('rt.broadcast_failed', ['exception' => class_basename($e), 'conv_id' => $id]);
                    break;
                }
            }
        } catch (\Throwable $e) {
            WaLog::error('rt.flush_failed', ['exception' => class_basename($e)]);
        }
    }
}
