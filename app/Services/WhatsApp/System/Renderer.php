<?php

namespace App\Services\WhatsApp\System;

use App\Models\WaOutbox;

/**
 * نص رسالة النظام ومرفقها لحظة الإرسال (DispatchOutboxItem): الصلاحية من البيانات الآن (Context)، ثم القالب الفعّال.
 * صف الاختبار يحمل سطراً أول يسمّي النوع، فلا يُظن أنه رسالة حقيقية.
 *
 * @return array{ok: bool, reason?: string, text?: string, attachment?: ?array}
 */
final class Renderer
{
    public static function render(WaOutbox $item): array
    {
        $type = Catalog::effective((string) $item->type_key);
        if ($type === null) {
            return ['ok' => false, 'reason' => 'نوع غير معروف'];
        }
        $p = (array) $item->payload;
        $ctx = Context::for((string) $item->type_key, (string) $item->source_type, (int) $item->source_id, (array) ($p['anchor'] ?? []));
        // تجربة نوع لا مصدر له من صفحة رسائل النظام (طلب، عميل، محادثة): قيم نموذجية بدل بيانات عميل
        if (!$ctx['valid'] && $item->is_test && (int) $item->source_id === 0) {
            $ctx = ['valid' => true, 'vars' => self::sample((string) $item->type_key)];
        }
        if (!$ctx['valid']) {
            return ['ok' => false, 'reason' => (string) $ctx['reason']];
        }
        $vars = $ctx['vars'] + (isset($p['text']) ? ['text' => (string) $p['text']] : []);
        $r = TemplateRenderer::render((string) $type['template'], $vars);
        if (!$r['ok']) {
            return ['ok' => false, 'reason' => 'متغير ناقص في القالب: '.implode('، ', $r['missing'])];
        }
        $text = $item->is_test ? '🧪 [اختبار: '.$type['label'].(filled($p['test_of'] ?? null) ? ' — عميل ينتهي بـ '.$p['test_of'] : '')."]\n".$r['text'] : $r['text'];
        $attachment = isset($type['attachment']) ? ['generator' => $type['attachment'], 'id' => (int) $item->source_id] : null;

        return ['ok' => true, 'text' => $text, 'attachment' => $attachment];
    }

    /** @return array<string, string> */
    public static function sample(string $typeKey): array
    {
        $vars = array_fill_keys((array) (Catalog::TYPES[$typeKey]['vars'] ?? []), '—');

        return ['client_name' => 'أحمد', 'company' => (string) (\App\Services\CompanyInfo::all()['name'] ?? 'نُسك'), 'request_number' => 'Q-1001',
            'destination' => 'مكة المكرمة', 'trip_date' => now()->addDays(3)->toDateString(), 'date' => now()->toDateString()] + $vars;
    }

    /** معاينة على مصدر حقيقي (صفحة رسائل النظام) بلا إرسال */
    public static function preview(string $typeKey, string $template, string $sourceType, int $sourceId): array
    {
        $ctx = Context::for($typeKey, $sourceType, $sourceId);
        if (!$ctx['valid']) {
            return ['ok' => false, 'reason' => (string) $ctx['reason']];
        }

        return TemplateRenderer::render($template, $ctx['vars'] + ['text' => 'نص الموظف']) + ['reason' => null];
    }
}
