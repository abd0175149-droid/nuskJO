<?php

namespace App\Services\WhatsApp\System;

/**
 * يستبدل {متغير} في قالب رسالة النظام. متغير ناقص أو فارغ = رفض الإرسال بسبب واضح، لا رسالة ناقصة تصل العميل
 * («أهلاً ، فاتورتك…»). النص حتى 4096 حرفاً (حد واتساب ويب). سياسة المحتوى الصادر تمر لاحقاً في WhatsAppChannel.
 *
 * @return array{ok: bool, text: string, missing: list<string>}
 */
final class TemplateRenderer
{
    public static function render(string $template, array $vars): array
    {
        $missing = [];
        $text = preg_replace_callback('/\{([a-z_]{2,40})\}/', function ($m) use ($vars, &$missing) {
            $v = $vars[$m[1]] ?? null;
            if ($v === null || (is_string($v) && trim($v) === '')) {
                $missing[] = $m[1];

                return $m[0];
            }

            return is_scalar($v) ? (string) $v : '';
        }, $template) ?? $template;
        $text = trim(mb_substr($text, 0, (int) config('whatsapp.openwa.max_text', 4096)));

        return ['ok' => $missing === [] && $text !== '', 'text' => $text, 'missing' => array_values(array_unique($missing))];
    }

    /** @return list<string> المتغيرات في قالب */
    public static function variables(string $template): array
    {
        preg_match_all('/\{([a-z_]{2,40})\}/', $template, $m);

        return array_values(array_unique($m[1]));
    }
}
