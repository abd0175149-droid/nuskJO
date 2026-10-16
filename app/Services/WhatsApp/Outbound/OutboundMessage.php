<?php

namespace App\Services\WhatsApp\Outbound;

use App\Services\WhatsApp\TextNormalizer;
use App\Services\WhatsApp\WhatsAppChannel;

/**
 * رسالة صادرة واحدة بشكلها الرسمي في Cloud API (E32، مغلف WhatsAppChannel::send). قيمة ثابتة لا تعرف المحادثة:
 * الوجهة (to أو recipient) يضيفها dispatch، والاقتباس يُقبل في send() من المحادثة نفسها وحدها.
 *
 * الأنواع: text وimage وdocument وvideo. الوسائط بالمعرّف من POST /media، والرابط العام للصورة وحدها (مسار بطاقة
 * العرض القديم)؛ الوثيقة لا تُرسل برابط عام أبداً. E33 وE34 تسجّلان أنواعهما (location وcontacts وreaction وaudio)
 * بـ registerType على المغلف نفسه.
 */
final class OutboundMessage
{
    public const TYPES = ['text', 'image', 'document', 'video'];

    public const MEDIA_TYPES = ['image', 'document', 'video'];

    public const MAX_TEXT = 4096;

    public const MAX_CAPTION = 1024;

    public const MAX_FILENAME = 200;

    /** @var array<string, array{payload: callable(self): array, display: callable(self): string}> */
    private static array $registered = [];

    private function __construct(
        public readonly string $type,
        public readonly ?string $body = null,
        public readonly ?string $mediaId = null,
        public readonly ?string $link = null,
        public readonly ?string $filename = null,
        public readonly ?string $caption = null,
        public readonly ?string $replyToWamid = null,
        public readonly bool $previewUrl = false,
        /** @var array<string, mixed> بيانات نوع مسجّل */
        public readonly array $data = [],
    ) {}

    public static function text(string $body, bool $previewUrl = false): self
    {
        return new self('text', body: $body, previewUrl: $previewUrl);
    }

    /** صورة بالمعرّف، أو برابط https (مسار بطاقة العرض القديم وحده) */
    public static function image(?string $mediaId = null, ?string $caption = null, ?string $link = null): self
    {
        return new self('image', mediaId: self::id($mediaId), link: $link, caption: self::caption($caption));
    }

    /** الاسم بامتداده (يصححه OutboundMediaPolicy من النوع الحقيقي)، وبلا امتداد يُفترض pdf */
    public static function document(?string $mediaId, string $filename, ?string $caption = null): self
    {
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        return new self('document', mediaId: self::id($mediaId), filename: self::filename($filename, preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : 'pdf'),
            caption: self::caption($caption));
    }

    public static function video(?string $mediaId, ?string $caption = null): self
    {
        return new self('video', mediaId: self::id($mediaId), caption: self::caption($caption));
    }

    /** نوع سجّلته ملحمة لاحقة (E33/E34)؛ $data يقرؤه منشئ الحمولة المسجّل */
    public static function custom(string $type, array $data): self
    {
        return new self($type, data: $data);
    }

    /** وسيط بنوعه (من صف محجوز أو من فحص الملف) */
    public static function media(string $type, ?string $mediaId, ?string $filename = null, ?string $caption = null): self
    {
        return match ($type) {
            'image' => self::image($mediaId, $caption),
            'video' => self::video($mediaId, $caption),
            default => self::document($mediaId, (string) $filename, $caption),
        };
    }

    public function withReplyTo(?string $wamid): self
    {
        $wamid = is_string($wamid) && trim($wamid) !== '' ? mb_substr(trim($wamid), 0, 128) : null;

        return new self($this->type, $this->body, $this->mediaId, $this->link, $this->filename, $this->caption, $wamid, $this->previewUrl, $this->data);
    }

    public function withMediaId(string $mediaId): self
    {
        return new self($this->type, $this->body, self::id($mediaId), $this->link, $this->filename, $this->caption, $this->replyToWamid, $this->previewUrl, $this->data);
    }

    public function isMedia(): bool
    {
        return in_array($this->type, self::MEDIA_TYPES, true);
    }

    /**
     * يسجّل نوعاً جديداً على المغلف (E33: location وcontacts وreaction، E34: audio). $payload يعيد كائن النوع
     * (بلا messaging_product ولا وجهة)، و$display نص صف wa_messages في الصندوق.
     *
     * @param  callable(self): array  $payload
     * @param  callable(self): string  $display
     */
    public static function registerType(string $type, callable $payload, callable $display): void
    {
        if (in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("النوع {$type} مبني في المغلف");
        }
        self::$registered[$type] = ['payload' => $payload, 'display' => $display];
    }

    /** للاختبارات: إزالة نوع مسجّل */
    public static function forgetType(string $type): void
    {
        unset(self::$registered[$type]);
    }

    /** @return list<string> */
    public static function types(): array
    {
        return [...self::TYPES, ...array_keys(self::$registered)];
    }

    /**
     * فحص قبل أي نداء: null = سليمة، وإلا نتيجة فشل بشكل WhatsAppChannel (ok=false بلا HTTP).
     * $deferred: صف يُحجز قبل رفع الملف (المعرّف يأتي من الوظيفة بعد الرفع).
     */
    public function validate(bool $deferred = false): ?array
    {
        if (!in_array($this->type, self::types(), true)) {
            return self::fail(WhatsAppChannel::ERR_UNSUPPORTED, 'نوع الرسالة غير مدعوم.');
        }
        if ($this->type === 'text' && trim((string) $this->body) === '') {
            return self::fail('EMPTY_MESSAGE', 'لا يُرسل نص فارغ.');
        }
        if (!$this->isMedia()) {
            return null;
        }
        // الرابط العام للصورة وحدها (بطاقة العرض بلا بيانات شخصية)؛ الوثيقة والفيديو بالمعرّف فقط
        if ($this->link !== null) {
            if ($this->type !== 'image') {
                return self::fail(WhatsAppChannel::ERR_UNSUPPORTED, 'الوثائق لا تُرسل برابط عام — تُرفع إلى ميتا وتُرسل بالمعرّف.');
            }

            return str_starts_with($this->link, 'https://') ? null : self::fail('BAD_MEDIA_URL', 'رابط الصورة يجب أن يكون HTTPS ليقبله واتساب.');
        }
        if ($this->mediaId === null && !$deferred) {
            return self::fail('MEDIA_ID_MISSING', 'الملف لم يُرفع إلى ميتا بعد.');
        }
        if ($this->type === 'document' && ($this->filename === null || $this->filename === '')) {
            return self::fail('FILENAME_MISSING', 'اسم الملف مطلوب للمستند.');
        }

        return null;
    }

    /**
     * الحمولة الرسمية بلا to ولا recipient (يضيفهما dispatch). المفاتيح بترتيب الأغلفة القديمة (WrappersBackCompatTest).
     *
     * @return array<string, mixed>
     */
    public function payload(?string $contextWamid = null): array
    {
        $object = match ($this->type) {
            'text' => ['preview_url' => $this->previewUrl, 'body' => mb_substr((string) $this->body, 0, self::MAX_TEXT)],
            'image' => ($this->link !== null ? ['link' => $this->link] : ['id' => (string) $this->mediaId]) + self::captionField($this->caption),
            'document' => ['id' => (string) $this->mediaId, 'filename' => (string) $this->filename] + self::captionField($this->caption),
            'video' => ['id' => (string) $this->mediaId] + self::captionField($this->caption),
            default => (self::$registered[$this->type]['payload'] ?? fn () => [])($this),
        };

        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'type' => $this->type, $this->type => $object];
        if ($contextWamid !== null) {
            $payload['context'] = ['message_id' => $contextWamid];
        }

        return $payload;
    }

    /** نص صف wa_messages: بصيغة الوارد نفسها (InboundMessage) ليقرأها الصندوق والبحث وسياق البوت متسقة */
    public function displayBody(): string
    {
        $caption = $this->caption !== null ? ': '.$this->caption : '';

        return match ($this->type) {
            'text' => (string) $this->body,
            'image' => '📷 صورة'.$caption,
            'document' => '📄 ملف: '.$this->filename.$caption,
            'video' => '🎬 مقطع فيديو'.$caption,
            default => (string) (self::$registered[$this->type]['display'] ?? fn () => '')($this),
        };
    }

    /**
     * اسم ملف آمن للعميل: بلا محارف تحكم أو اتجاه، ولا فواصل مسار، وبامتداد مطابق للنوع، وحتى 200 حرف.
     * القصّ بـ mb_substr، لا trim بقائمة محارف (nuskjo-utf8-trim-bug).
     */
    public static function filename(?string $name, string $ext): string
    {
        $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'bin');
        $clean = TextNormalizer::untrustedName((string) $name, 255) ?? '';
        $clean = trim(preg_replace('/[\/\\\\:*?"<>|\p{Cf}]+/u', ' ', $clean) ?? '');
        $base = preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $clean) ?? $clean;
        $base = trim(preg_replace('/\s+/u', ' ', $base) ?? '', ' .');
        if ($base === '') {
            $base = 'ملف';
        }

        return mb_substr($base, 0, self::MAX_FILENAME - mb_strlen($ext) - 1).'.'.$ext;
    }

    private static function caption(?string $caption): ?string
    {
        if ($caption === null || trim($caption) === '') {
            return null;
        }

        return mb_substr($caption, 0, self::MAX_CAPTION);
    }

    private static function captionField(?string $caption): array
    {
        return $caption !== null ? ['caption' => $caption] : [];
    }

    private static function id(?string $mediaId): ?string
    {
        $mediaId = is_string($mediaId) ? trim($mediaId) : '';

        return $mediaId !== '' ? mb_substr($mediaId, 0, 128) : null;
    }

    private static function fail(string $code, string $error): array
    {
        return ['ok' => false, 'error' => $error, 'code' => $code, 'message_id' => null, 'meta_code' => null];
    }
}
