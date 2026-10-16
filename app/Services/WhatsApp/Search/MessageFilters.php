<?php

namespace App\Services\WhatsApp\Search;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * فلاتر الرسائل (E30): الاتجاه والمصدر والنوع والفترة، بقواعد تحقق واحدة يشترك فيها البحث وخيط المحادثة.
 * الفترة بأيام الأردن: «من 2026-10-01» تبدأ منتصف ليل عمّان، والتخزين بتوقيت التطبيق (UTC).
 */
final class MessageFilters
{
    public const DIRECTIONS = ['in', 'out'];

    public const SOURCES = ['customer', 'bot', 'staff', 'system'];

    public const TYPES = ['text', 'image', 'document', 'audio', 'video', 'sticker', 'location', 'contacts', 'interactive', 'button'];

    public const KEYS = ['direction', 'source', 'msg_type', 'date_from', 'date_to'];

    public const TZ = 'Asia/Amman';

    /** @return array<string, array> */
    public static function rules(): array
    {
        return [
            'direction' => ['nullable', Rule::in(self::DIRECTIONS)],
            'source' => ['nullable', Rule::in(self::SOURCES)],
            // نوع أو أكثر مفصولة بفواصل («صور وملفات» = image,document,video)
            'msg_type' => ['nullable', 'string', 'max:120', 'regex:/^[a-z_]+(,[a-z_]+)*$/', function ($attribute, $value, $fail) {
                if (array_diff(self::types($value), self::TYPES)) {
                    $fail('نوع رسالة غير معروف');
                }
            }],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    /** @return list<string> أسماء الفلاتر المضبوطة (للتدقيق: الأسماء لا القيم) */
    public static function present(array $v): array
    {
        return array_values(array_filter(self::KEYS, fn ($k) => filled($v[$k] ?? null)));
    }

    /** @return list<string> */
    public static function types(?string $csv): array
    {
        return array_values(array_unique(array_filter(explode(',', (string) $csv))));
    }

    /** يضيّق استعلام رسائل (Eloquent أو DB) بالفلاتر المضبوطة؛ $column يبني اسم العمود (مؤهّلاً عند الربط) */
    public static function apply($query, array $v, string $table = ''): void
    {
        $col = fn (string $name) => $table === '' ? $name : "{$table}.{$name}";
        if (filled($v['direction'] ?? null)) {
            $query->where($col('direction'), $v['direction']);
        }
        if (filled($v['source'] ?? null)) {
            $query->where($col('source'), $v['source']);
        }
        if ($types = self::types($v['msg_type'] ?? null)) {
            $query->whereIn($col('msg_type'), $types);
        }
        if (filled($v['date_from'] ?? null)) {
            $query->where($col('created_at'), '>=', self::bound($v['date_from'], false));
        }
        if (filled($v['date_to'] ?? null)) {
            $query->where($col('created_at'), '<=', self::bound($v['date_to'], true));
        }
    }

    /** يوم بلا وقت = يوم عمّان كاملاً؛ ومع وقت = الوقت نفسه بتوقيت عمّان. الناتج بتوقيت التطبيق كما يُخزَّن */
    public static function bound(string $value, bool $end): string
    {
        $at = Carbon::parse($value, self::TZ);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            $at = $end ? $at->endOfDay() : $at->startOfDay();
        }

        return $at->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
