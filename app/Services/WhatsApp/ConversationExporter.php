<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Support\Csv;
use Illuminate\Support\Carbon;

/**
 * تصدير محادثة واحدة (E30.6) لنزاع أو طلب اطّلاع: TXT أو CSV، UTF-8 مع BOM، بدفعات من 500.
 * الرسائل وحدها: لا الحمولة الخام ولا الترجمات ولا الوسوم ولا الملاحظات الداخلية ولا بيانات مالية، والوسائط
 * بنصها البديل بلا ملفات. الأوقات بتوقيت الأردن. CSV عبر App\Support\Csv (محمي من حقن الصيغ).
 */
final class ConversationExporter
{
    public const TZ = 'Asia/Amman';

    private const COLUMNS = ['id', 'conversation_id', 'direction', 'source', 'msg_type', 'body', 'status', 'staff_id', 'created_at'];

    /** نص بديل لوسائط بلا نص */
    private const PLACEHOLDERS = [
        'image' => '📷 صورة', 'video' => '🎬 مقطع فيديو', 'audio' => '🎤 رسالة صوتية', 'document' => '📄 ملف',
        'sticker' => '🏷️ ملصق', 'location' => '📍 موقع', 'contacts' => '👤 جهة اتصال',
    ];

    /** صادرة لم يثبت وصولها: تُعلَّم في الملف كي لا يُظن أن العميل رآها */
    private const UNDELIVERED = ['failed' => 'لم تُرسل', 'blocked' => 'حُجبت ولم تُرسل', 'queued' => 'قيد الإرسال', 'unknown' => 'غير مؤكد الوصول'];

    public static function maxRows(): int
    {
        return (int) config('whatsapp.export.max_rows', 20000);
    }

    /** الفترة بأيام الأردن: «من» منتصف ليل يومها و«إلى» نهاية يومها، بتوقيت التطبيق كما يُخزَّن */
    public static function range(?string $from, ?string $to): array
    {
        $tz = config('app.timezone');

        return [
            $from ? Carbon::parse($from, self::TZ)->startOfDay()->setTimezone($tz) : null,
            $to ? Carbon::parse($to, self::TZ)->endOfDay()->setTimezone($tz) : null,
        ];
    }

    public function count(WaConversation $c, ?Carbon $from, ?Carbon $to): int
    {
        return $this->query($c, $from, $to)->count();
    }

    /**
     * يكتب الملف في $h ويعيد عدد الرسائل.
     *
     * @param  resource  $h
     */
    public function stream(WaConversation $c, string $format, ?Carbon $from, ?Carbon $to, $h, ?User $by = null): int
    {
        Csv::bom($h);
        if ($format === 'csv') {
            Csv::put($h, ['message_id', 'at', 'direction', 'source', 'sender', 'type', 'status', 'body']);
        } else {
            fwrite($h, $this->header($c, $from, $to, $by));
        }

        $n = 0;
        $this->query($c, $from, $to)->select(self::COLUMNS)->with('staff:id,name')
            ->chunkById(500, function ($rows) use ($h, $format, &$n) {
                foreach ($rows as $m) {
                    $format === 'csv' ? Csv::put($h, $this->csvRow($m)) : fwrite($h, $this->txtLine($m));
                    $n++;
                }
            });

        return $n;
    }

    private function query(WaConversation $c, ?Carbon $from, ?Carbon $to)
    {
        return $c->messages()
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from->format('Y-m-d H:i:s')))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to->format('Y-m-d H:i:s')));
    }

    private function header(WaConversation $c, ?Carbon $from, ?Carbon $to, ?User $by): string
    {
        $local = fn (?Carbon $at) => $at?->copy()->setTimezone(self::TZ)->format('Y-m-d');
        $period = $from || $to ? 'من '.($local($from) ?? 'البداية').' إلى '.($local($to) ?? 'اليوم') : 'المحادثة كاملة';
        $lines = [
            "محادثة واتساب #{$c->id} — ".self::oneLine($c->noticeLabel()),
            'الرقم: '.($c->hasPhone() ? '+'.$c->phone : 'غير ظاهر'),
            'التصدير: '.now()->setTimezone(self::TZ)->format('Y-m-d H:i').' بواسطة '.self::oneLine($by?->name ?? 'النظام'),
            "الفترة: {$period}",
            'الأوقات بتوقيت الأردن (Asia/Amman).',
            str_repeat('—', 40),
        ];

        return implode("\n", $lines)."\n";
    }

    private function txtLine(WaMessage $m): string
    {
        $at = $m->created_at?->copy()->setTimezone(self::TZ)->format('Y-m-d H:i');
        $body = $this->body($m);
        // الأسطر التالية من الرسالة نفسها بإزاحة، فلا تُقرأ رسالة مستقلة
        $body = str_replace("\n", "\n    ", preg_replace('/\r\n?/', "\n", $body) ?? $body);
        $mark = $m->direction === 'out' && isset(self::UNDELIVERED[$m->status]) ? ' ⟨'.self::UNDELIVERED[$m->status].'⟩' : '';

        return "[{$at}] {$this->speaker($m)}: {$body}{$mark}\n";
    }

    private function csvRow(WaMessage $m): array
    {
        return [
            $m->id,
            $m->created_at?->copy()->setTimezone(self::TZ)->toIso8601String(),
            $m->direction,
            $m->source,
            $this->speaker($m),
            $m->msg_type,
            $m->status,
            $this->body($m),
        ];
    }

    private function speaker(WaMessage $m): string
    {
        return match (true) {
            $m->source === 'staff' => 'موظف: '.self::oneLine($m->staff?->name ?? 'غير معروف'),
            $m->source === 'bot' => 'البوت',
            $m->source === 'system' => 'النظام',
            $m->direction === 'in' => 'العميل',
            default => (string) $m->source,
        };
    }

    private function body(WaMessage $m): string
    {
        $body = trim((string) $m->body);
        if ($m->msg_type === 'reaction') {
            return 'تفاعل: '.($body !== '' ? $body : '—');
        }
        // بطاقة جهة اتصال بالاسم وحده (E33): رسائل قبل E33 حملت الرقم في النص
        if ($m->msg_type === 'contacts') {
            $body = trim(preg_replace('/\s*—?\s*\+?\d[\d\s\-().]{5,}\d/u', '', $body) ?? $body);
        }

        return $body !== '' ? $body : (self::PLACEHOLDERS[$m->msg_type] ?? '['.$m->msg_type.']');
    }

    private static function oneLine(string $text): string
    {
        return trim(preg_replace('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $text) ?? '');
    }
}
