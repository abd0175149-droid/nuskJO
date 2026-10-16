<?php

namespace App\Services\WhatsApp\Outbound;

use App\Support\FeatureFlags;

/**
 * فحص الملف الصادر قبل حفظه أو رفعه (E32.4). النوع الحقيقي بـ finfo من المحتوى، لا من ترويسة المتصفح ولا من الامتداد،
 * من قائمة بيضاء بحدّ لكل نوع (حدود ميتا: الصورة 5MB، والفيديو 16MB، والمستند بقرار المالك تحت 100MB).
 *
 * الصورة JPEG أو PNG بألوان 8-bit RGB/RGBA (CMYK وPNG 16-bit يُرفضان؛ HEIC وWebP يحوّلهما المتصفح). الفيديو H.264 مع AAC
 * بفحص صناديقه (Mp4Probe). وPDF بلا محتوى نشط (/JavaScript و/JS و/Launch والملفات المضمّنة) في القواميس وفي مجاري الكائنات
 * المضغوطة، لأننا نرسله لعملاء ولا نريد أن نكون ناقل ملفات خطرة. Office الحديثة وtxt بقرار المالك، والقديمة ذات الماكرو
 * مرفوضة دائماً. لا اسم ملف في أي سجل: البصمة وحدها.
 */
final class OutboundMediaPolicy
{
    /** النوع المكتشف => [نوع الرسالة، الحد بالميغابايت (null = حد المستندات)، الامتدادات] */
    public const ALLOWED = [
        'image/jpeg' => ['image', 5, ['jpg', 'jpeg']],
        'image/png' => ['image', 5, ['png']],
        'video/mp4' => ['video', 16, ['mp4']],
        'video/3gpp' => ['video', 16, ['3gp']],
        'application/pdf' => ['document', null, ['pdf']],
    ];

    /** بقرار المالك (whatsapp.outbound.office_docs): تُكتشف بالجزء الرئيسي داخل ZIP لا باسم الملف */
    public const OFFICE = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['document', null, ['docx'], 'word/document.xml'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['document', null, ['xlsx'], 'xl/workbook.xml'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['document', null, ['pptx'], 'ppt/presentation.xml'],
        'text/plain' => ['document', null, ['txt'], null],
    ];

    /** نوع الوثيقة في واجهة الإرسال (مراجعة E32): التأشيرة تحمل رقم الجواز فتطلب تأكيداً إضافياً وتُدقَّق بدرجة تحذير */
    public const DOC_KINDS = ['invoice' => 'فاتورة', 'visa' => 'تأشيرة', 'ticket' => 'تذكرة', 'other' => 'أخرى'];

    /** أسماء PDF تنفّذ شيئاً أو تحمل ملفاً داخلها */
    private const PDF_ACTIVE = '~/(?:JavaScript|JS|Launch|EmbeddedFiles?|RichMedia)(?![A-Za-z0-9])~';

    /** سقف ما يُفك من مجاري الكائنات المضغوطة في PDF واحد */
    private const PDF_INFLATE_MAX = 8 * 1024 * 1024;

    /** @return array<string, array{0: string, 1: ?int, 2: list<string>, 3?: ?string}> */
    public static function allowed(): array
    {
        $types = self::ALLOWED;
        if (!config('whatsapp.outbound.video', true)) {
            $types = array_filter($types, fn ($t) => $t[0] !== 'video');
        }

        return config('whatsapp.outbound.office_docs') ? $types + self::OFFICE : $types;
    }

    public static function limitBytes(string $mime): int
    {
        $mb = self::allowed()[$mime][1] ?? null;

        return (int) (($mb ?? self::documentMb()) * 1024 * 1024);
    }

    public static function documentMb(): int
    {
        return (int) config('whatsapp.outbound.max_document_mb', 16);
    }

    /** أكبر حد بين الأنواع المسموحة (KB) لقاعدة max في التحقق */
    public static function maxUploadKb(): int
    {
        $max = 0;
        foreach (array_keys(self::allowed()) as $mime) {
            $max = max($max, self::limitBytes($mime));
        }

        return intdiv($max, 1024);
    }

    /** ما يحتاجه المنشئ في المتصفح: الأنواع وحدودها، والقبول، وأنواع الوثائق (الخادم يفحص كل شيء من جديد) */
    public static function forClient(): array
    {
        $types = [];
        foreach (self::allowed() as $mime => $t) {
            $types[] = ['mime' => $mime, 'kind' => $t[0], 'max' => self::limitBytes($mime), 'exts' => $t[2]];
        }
        $exts = array_merge(...array_map(fn ($t) => $t['exts'], $types));

        return [
            'enabled' => FeatureFlags::on('staff_file_send'),
            'types' => $types,
            // HEIC وWebP يقبلهما المنتقي لأن المتصفح يحوّلهما إلى JPEG قبل الرفع
            'accept' => implode(',', [...array_map(fn ($e) => '.'.$e, $exts), ...array_column($types, 'mime'), '.heic', '.heif', '.webp', 'image/heic', 'image/heif', 'image/webp']),
            'doc_kinds' => self::DOC_KINDS,
            'max_caption' => OutboundMessage::MAX_CAPTION,
        ];
    }

    /**
     * @return array{ok: bool, type: ?string, mime: ?string, ext: ?string, name: ?string, size: int, sha256: ?string, code: ?string, error: ?string, warning: ?string}
     */
    public static function inspect(string $path, string $clientName): array
    {
        clearstatcache(true, $path);
        if (!is_file($path) || !is_readable($path)) {
            return self::reject('MEDIA_UNREADABLE', 'تعذّر قراءة الملف.');
        }
        $size = (int) filesize($path);
        if ($size <= 0) {
            return self::reject('MEDIA_EMPTY', 'الملف فارغ.', $size);
        }

        $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        // عائلة Office وZIP والنص: فحص الأجزاء هو الحَكَم (ملف بماكرو قد يسمّيه finfo «Word» ويبقى مرفوضاً)
        $mime = in_array($detected, ['application/zip', 'application/octet-stream', ...array_keys(self::OFFICE)], true)
            ? (self::officeType($path, $detected) ?? 'application/x-refused')
            : $detected;
        $spec = self::allowed()[$mime] ?? null;
        if ($spec === null) {
            return match (true) {
                in_array($detected, ['image/heic', 'image/heif', 'image/webp'], true) => self::reject('MEDIA_CONVERT', 'حوّل الصورة إلى JPG من هاتفك أو إلى PDF ثم أرسلها.', $size),
                str_starts_with($detected, 'image/') => self::reject('MEDIA_TYPE', 'نوع الصورة غير مقبول؛ أرسلها JPG أو PNG.', $size),
                str_starts_with($detected, 'video/') => self::reject('MEDIA_TYPE', 'صيغة الفيديو غير مقبولة؛ أرسله MP4 بترميز H.264.', $size),
                default => self::reject('MEDIA_TYPE', 'نوع الملف غير مسموح. المسموح: صورة JPG أو PNG، وPDF، وفيديو MP4'
                    .(config('whatsapp.outbound.office_docs') ? '، ومستندات Word وExcel وPowerPoint ونص' : '').'.', $size),
            };
        }
        [$type, , $exts] = $spec;

        // الامتداد من الاسم الأصلي إن طابق النوع، وإلا يُصحَّح بالامتداد الحقيقي
        $clientExt = strtolower((string) pathinfo($clientName, PATHINFO_EXTENSION));
        $ext = in_array($clientExt, $exts, true) ? $clientExt : $exts[0];
        $name = OutboundMessage::filename($clientName, $ext);

        if ($size > self::limitBytes($mime)) {
            $mb = intdiv(self::limitBytes($mime), 1024 * 1024);

            return self::reject('MEDIA_TOO_LARGE', match ($type) {
                'image' => "الصورة أكبر من {$mb}MB — صغّرها أو حوّلها إلى PDF.",
                'video' => "الفيديو أكبر من {$mb}MB — قصّه أو أرسله رابطاً.",
                default => "الملف أكبر من {$mb}MB المسموح بها.",
            }, $size);
        }

        $warning = null;
        if ($type === 'image') {
            $info = @getimagesize($path);
            if ($info === false) {
                return self::reject('IMAGE_INVALID', 'تعذّر قراءة الصورة؛ قد تكون تالفة.', $size);
            }
            if (($info['channels'] ?? 3) === 4) {
                return self::reject('IMAGE_CMYK', 'الصورة بألوان CMYK للطباعة؛ حوّل الصورة إلى RGB ثم أرسلها.', $size);
            }
            if ($mime === 'image/png' && (int) ($info['bits'] ?? 8) > 8) {
                return self::reject('IMAGE_16BIT', 'صورة PNG بعمق 16-bit لا يقبلها واتساب؛ احفظها 8-bit أو JPG.', $size);
            }
        } elseif ($type === 'video') {
            $probe = Mp4Probe::inspect($path);
            if (!$probe['ok']) {
                return self::reject((string) $probe['code'], (string) $probe['error'], $size);
            }
            if (!$probe['faststart']) {
                $warning = 'يُفضَّل تصدير الفيديو بخيار faststart ليبدأ تشغيله أسرع عند العميل.';
            }
        } elseif ($mime === 'application/pdf') {
            if ($fail = self::pdfActive($path)) {
                return self::reject($fail[0], $fail[1], $size);
            }
        }

        return ['ok' => true, 'type' => $type, 'mime' => $mime, 'ext' => $ext, 'name' => $name, 'size' => $size,
            'sha256' => hash_file('sha256', $path), 'code' => null, 'error' => null, 'warning' => $warning];
    }

    /**
     * Office الحديثة: ZIP فيه [Content_Types].xml والجزء الرئيسي للنوع، بلا ماكرو (vbaProject.bin أو macroEnabled).
     * text/plain كما هو. null: ليس من هذه الأنواع أو أنها مطفأة.
     */
    private static function officeType(string $path, string $detected): ?string
    {
        if (!config('whatsapp.outbound.office_docs')) {
            return null;
        }
        if ($detected === 'text/plain') {
            return 'text/plain';
        }
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return null;
        }
        try {
            $types = $zip->getFromName('[Content_Types].xml');
            if (!is_string($types) || stripos($types, 'macroEnabled') !== false) {
                return null;
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_ends_with(strtolower((string) $zip->getNameIndex($i)), 'vbaproject.bin')) {
                    return null;
                }
            }
            foreach (self::OFFICE as $mime => $spec) {
                if (($spec[3] ?? null) !== null && $zip->locateName($spec[3]) !== false) {
                    return $mime;
                }
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    /**
     * محتوى نشط في PDF: مسح القواميس خارج المجاري (المجاري الثنائية تُتخطّى فلا تعطي إنذاراً كاذباً)، وفكّ مجاري الكائنات
     * المضغوطة (ObjStm) التي تحمل قواميس، مع فك ترميز #xx في الأسماء.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function pdfActive(string $path): ?array
    {
        $data = (string) file_get_contents($path);
        if (!str_contains(substr($data, 0, 1024), '%PDF-')) {
            return ['PDF_INVALID', 'الملف ليس PDF سليماً.'];
        }

        $outside = '';
        $inflated = 0;
        $pos = 0;
        $len = strlen($data);
        while ($pos < $len) {
            $at = self::streamKeyword($data, $pos);
            if ($at === null) {
                $outside .= substr($data, $pos);
                break;
            }
            [$keyword, $bodyStart] = $at;
            $dict = substr($data, $pos, $keyword - $pos);
            $outside .= $dict;
            $end = strpos($data, 'endstream', $bodyStart);
            if ($end === false) {
                break;
            }
            $head = substr($dict, max(0, (int) strrpos($dict, 'obj')));
            if (stripos($head, '/ObjStm') !== false && $inflated < self::PDF_INFLATE_MAX) {
                $body = substr($data, $bodyStart, $end - $bodyStart);
                $plain = @gzuncompress($body, self::PDF_INFLATE_MAX) ?: @gzinflate($body, self::PDF_INFLATE_MAX) ?: '';
                $inflated += strlen($plain);
                $outside .= "\n".$plain."\n";
            }
            $pos = $end + 9;
        }

        $names = preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($m) => chr((int) hexdec($m[1])), $outside) ?? $outside;

        return preg_match(self::PDF_ACTIVE, $names) === 1
            ? ['PDF_ACTIVE', 'الملف يحوي محتوى نشطاً (سكربت أو ملفات مضمّنة)؛ احفظه PDF عادياً من جديد ثم أرسله.']
            : null;
    }

    /** موضع الكلمة stream (لا endstream) يليها سطر جديد، وبداية جسم المجرى بعدها */
    private static function streamKeyword(string $data, int $from): ?array
    {
        while (($at = strpos($data, 'stream', $from)) !== false) {
            $from = $at + 6;
            if ($at >= 3 && substr($data, $at - 3, 3) === 'end') {
                continue;
            }
            if (substr($data, $at + 6, 2) === "\r\n") {
                return [$at, $at + 8];
            }
            if (in_array(substr($data, $at + 6, 1), ["\n", "\r"], true)) {
                return [$at, $at + 7];
            }
        }

        return null;
    }

    private static function reject(string $code, string $error, int $size = 0): array
    {
        return ['ok' => false, 'type' => null, 'mime' => null, 'ext' => null, 'name' => null, 'size' => $size, 'sha256' => null,
            'code' => $code, 'error' => $error, 'warning' => null];
    }
}
