<?php

namespace App\Services\WhatsApp\OpenWa;

use App\Services\WhatsApp\Media\MediaArchiver;

/**
 * الحمولة الداخلية (بشكل Cloud API الذي يبنيه OutboundMessage وتفحصه OutboundPolicy وتُحفظ في wa_messages.payload) إلى
 * نداء OpenWA: [المسار داخل الجلسة، الجسم]. الشكل الداخلي يبقى كما هو فلا تتغير السياسة ولا السجل ولا العرض.
 *
 * - text ← send-text (quotedMessageId للاقتباس). معاينة الرابط: linkPreview.
 * - image/document/video ← send-*: base64 من الملف المحلي. المعرّف «local:{مسار}» من uploadMedia يشير إلى قرص wa_media،
 *   ورابط https على APP_URL/storage يُقرأ من القرص العام مباشرة، وغيره يُمرَّر url (تجلبه البوابة).
 * - audio ← send-audio (ptt=true للرسالة الصوتية).
 * - location ← send-location، contacts ← send-contact (أول بطاقة)، reaction ← react.
 * - interactive (أزرار وقوائم ونموذج وطلب الرقم): لا مقابل لها في واتساب ويب، فتُرسل نصاً بخيارات مرقمة يحلها ChoiceResolver.
 */
final class PayloadMapper
{
    /** نوع الملف المحفوظ لكل نوع وسيط إن لم يُعرف */
    private const DEFAULT_MIME = ['image' => 'image/jpeg', 'video' => 'video/mp4', 'document' => 'application/pdf', 'audio' => 'audio/ogg; codecs=opus'];

    /**
     * @return array{0: string, 1: array<string, mixed>}
     *
     * @throws MapperException
     */
    public static function map(array $payload, string $chatId, array $columns = []): array
    {
        $type = (string) ($payload['type'] ?? 'text');
        $node = (array) ($payload[$type] ?? []);
        $quoted = data_get($payload, 'context.message_id');
        $quote = is_string($quoted) && $quoted !== '' ? ['quotedMessageId' => $quoted] : [];
        $max = (int) config('whatsapp.openwa.max_text', 4096);

        return match ($type) {
            'text' => ['/messages/send-text', ['chatId' => $chatId, 'text' => mb_substr((string) ($node['body'] ?? ''), 0, $max)]
                + (($node['preview_url'] ?? false) === true ? ['linkPreview' => true] : ['linkPreview' => false]) + $quote],
            'image', 'video', 'document' => ['/messages/send-'.$type, ['chatId' => $chatId] + self::media($type, $node, $columns) + $quote],
            'audio' => ['/messages/send-audio', ['chatId' => $chatId] + self::media('audio', $node, $columns)
                + (!empty($node['voice']) ? ['ptt' => true] : []) + $quote],
            'location' => ['/messages/send-location', array_filter([
                'chatId' => $chatId,
                'latitude' => isset($node['latitude']) ? (float) $node['latitude'] : null,
                'longitude' => isset($node['longitude']) ? (float) $node['longitude'] : null,
                'description' => isset($node['name']) ? mb_substr((string) $node['name'], 0, 200) : null,
                'address' => isset($node['address']) ? mb_substr((string) $node['address'], 0, 300) : null,
            ], fn ($v) => $v !== null) + $quote],
            'contacts' => self::contact($payload, $chatId, $quote),
            'reaction' => ['/messages/react', ['chatId' => $chatId, 'messageId' => (string) ($node['message_id'] ?? ''), 'emoji' => (string) ($node['emoji'] ?? '')]],
            'interactive' => ['/messages/send-text', ['chatId' => $chatId, 'text' => mb_substr(self::interactiveText($node), 0, $max), 'linkPreview' => false] + $quote],
            default => throw new MapperException('UNSUPPORTED'),
        };
    }

    /** نص الرسالة التفاعلية كما يراه العميل: النص ثم الخيارات مرقمة (1، 2، 3) ثم التذييل */
    public static function interactiveText(array $interactive): string
    {
        $lines = [];
        if (filled(data_get($interactive, 'header.text'))) {
            $lines[] = '*'.trim((string) data_get($interactive, 'header.text')).'*';
        }
        $lines[] = trim((string) data_get($interactive, 'body.text', ''));
        $options = self::options($interactive);
        if ($options !== []) {
            $lines[] = '';
            foreach ($options as $i => $o) {
                $lines[] = ($i + 1).'. '.$o['title'].(filled($o['description'] ?? null) ? ' — '.$o['description'] : '');
            }
            $lines[] = '';
            $lines[] = 'اكتب رقم الخيار.';
        } elseif (($interactive['type'] ?? '') === 'request_contact_info') {
            $lines[] = '';
            $lines[] = 'اكتب رقم هاتفك هنا، أو أرسل جهة اتصالك.';
        }
        if (filled(data_get($interactive, 'footer.text'))) {
            $lines[] = '';
            $lines[] = '_'.trim((string) data_get($interactive, 'footer.text')).'_';
        }

        return trim(implode("\n", $lines));
    }

    /**
     * الخيارات بالترتيب المعروض: أزرار الرد أو صفوف القائمة (عبر الأقسام).
     *
     * @return list<array{id: string, title: string, description?: string}>
     */
    public static function options(array $interactive): array
    {
        $out = [];
        if (($interactive['type'] ?? '') === 'button') {
            foreach ((array) data_get($interactive, 'action.buttons', []) as $b) {
                $out[] = ['id' => (string) data_get($b, 'reply.id', ''), 'title' => (string) data_get($b, 'reply.title', '')];
            }
        } elseif (($interactive['type'] ?? '') === 'list') {
            foreach ((array) data_get($interactive, 'action.sections', []) as $section) {
                foreach ((array) data_get($section, 'rows', []) as $r) {
                    $out[] = array_filter(['id' => (string) ($r['id'] ?? ''), 'title' => (string) ($r['title'] ?? ''),
                        'description' => isset($r['description']) ? (string) $r['description'] : null], fn ($v) => $v !== null);
                }
            }
        }

        return array_values(array_filter($out, fn ($o) => $o['title'] !== ''));
    }

    private static function contact(array $payload, string $chatId, array $quote): array
    {
        $card = (array) (array_values((array) ($payload['contacts'] ?? []))[0] ?? []);
        $name = (string) data_get($card, 'name.formatted_name', '');
        $number = preg_replace('/\D/', '', (string) (data_get($card, 'phones.0.wa_id') ?: data_get($card, 'phones.0.phone', ''))) ?? '';
        if ($name === '' || $number === '') {
            throw new MapperException('BAD_CONTACTS');
        }

        return ['/messages/send-contact', ['chatId' => $chatId, 'contactName' => mb_substr($name, 0, 120), 'contactNumber' => $number] + $quote];
    }

    /** @return array{base64?: string, url?: string, mimetype: string, filename?: string, caption?: string} */
    private static function media(string $type, array $node, array $columns): array
    {
        $caption = isset($node['caption']) && $node['caption'] !== '' ? ['caption' => mb_substr((string) $node['caption'], 0, (int) config('whatsapp.openwa.max_caption', 1024))] : [];
        $filename = $type === 'document' ? ['filename' => mb_substr((string) ($node['filename'] ?? ($columns['media_filename'] ?? 'file.pdf')), 0, 255)] : [];
        $mime = (string) ($columns['media_mime'] ?? '') ?: self::DEFAULT_MIME[$type];
        if ($type === 'audio' && !str_contains($mime, 'codecs')) {
            $mime = 'audio/ogg; codecs=opus';
        }

        $id = (string) ($node['id'] ?? '');
        $link = (string) ($node['link'] ?? '');
        $bytes = null;
        if (str_starts_with($id, 'local:')) {
            $bytes = self::readPrivate(substr($id, 6));
        } elseif ($link !== '' && ($public = self::publicPath($link)) !== null) {
            $bytes = @file_get_contents($public) ?: null;
            if ($bytes === null) {
                throw new MapperException('MEDIA_FILE_MISSING');
            }
            $mime = self::sniff($public, $mime);
        } elseif ($link !== '' && str_starts_with($link, 'https://')) {
            return ['url' => $link, 'mimetype' => $mime] + $filename + $caption;
        } elseif (filled($columns['media_path'] ?? null)) {
            $bytes = self::readPrivate((string) $columns['media_path']);
        }
        if ($bytes === null || $bytes === '') {
            throw new MapperException('MEDIA_FILE_MISSING');
        }

        return ['base64' => base64_encode($bytes), 'mimetype' => $mime] + $filename + $caption;
    }

    private static function readPrivate(string $path): ?string
    {
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return rescue(fn () => MediaArchiver::disk()->exists($path) ? MediaArchiver::disk()->get($path) : null, null, false);
    }

    /** رابط على APP_URL/storage/… : مساره على القرص العام، فلا تجلبه البوابة من الإنترنت عبر النفق */
    private static function publicPath(string $url): ?string
    {
        $base = rtrim((string) config('app.url'), '/').'/storage/';
        if (!str_starts_with($url, $base)) {
            return null;
        }
        $rel = rawurldecode(strtok(substr($url, strlen($base)), '?') ?: '');
        if ($rel === '' || str_contains($rel, '..')) {
            return null;
        }
        $path = storage_path('app/public/'.$rel);

        return is_file($path) ? $path : null;
    }

    private static function sniff(string $path, string $fallback): string
    {
        $mime = function_exists('mime_content_type') ? @mime_content_type($path) : false;

        return is_string($mime) && $mime !== '' ? $mime : $fallback;
    }
}
