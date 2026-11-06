<?php

namespace App\Services\WhatsApp\System;

use App\Models\WaMessageType;
use Illuminate\Support\Facades\Schema;

/**
 * سجل أنواع رسائل النظام في الكود (2026-11-06، docs/whatsapp/system-messages.md): المفتاح والمجموعة والتسمية والقالب
 * الافتراضي والتوقيت والمتغيرات. جدول wa_message_types يحفظ ما يعدّله المدير وحده (التفعيل والوضع والمرحلة والقالب
 * والتوقيت والرقم)، وeffective() يدمجهما. مفتاح غير موجود هنا لا يُرسل أبداً ولو وُجد صفه.
 *
 * التوقيت: anchor (now | trip_date | due_date | return_date | priced_at | daily) وoffset_days وat (HH:MM بتوقيت عمّان)،
 * وdelay_minutes للفوري. الوضع: auto يُرسل، approve ينتظر موافقة في الطابور، off لا يُجدول.
 * المرحلة: test يذهب لأرقام الاختبار وحدها، live للعملاء (قرار المالك: كل الأنواع تلقائية بعد اختبار بسيط لكل نوع).
 */
final class Catalog
{
    public const GROUPS = [
        'invoice' => 'الفواتير والسندات',
        'trip' => 'الرحلات',
        'quote' => 'عروض الأسعار',
        'lead' => 'المتابعات',
        'admin' => 'ملخص الإدارة',
    ];

    public const TYPES = [
        'invoice.approved' => [
            'group' => 'invoice', 'label' => 'فاتورة معتمدة', 'source' => 'invoice', 'priority' => 7, 'attachment' => 'invoice_pdf',
            'description' => 'عند اعتماد الفاتورة: نسخة PDF منها للعميل.',
            'timing' => ['anchor' => 'now', 'delay_minutes' => 2], 'expires_minutes' => 2880, 'cancel_on_reply' => false,
            'vars' => ['client_name', 'invoice_number', 'total', 'trip_date', 'company'],
            'template' => "أهلاً {client_name} 🌷\nمرفق فاتورتك رقم {invoice_number} بقيمة {total} دينار.\nرحلة موفقة إن شاء الله، ونحن بخدمتك لأي استفسار.\n{company}",
        ],
        'receipt.approved' => [
            'group' => 'invoice', 'label' => 'سند قبض معتمد', 'source' => 'receipt', 'priority' => 7, 'attachment' => 'receipt_pdf',
            'description' => 'عند اعتماد سند القبض: نسخة PDF منه للعميل مع المتبقي.',
            'timing' => ['anchor' => 'now', 'delay_minutes' => 2], 'expires_minutes' => 2880, 'cancel_on_reply' => false,
            'vars' => ['client_name', 'receipt_number', 'amount', 'remaining', 'company'],
            'template' => "أهلاً {client_name}\nاستلمنا دفعتك بقيمة {amount} دينار، ومرفق سند القبض رقم {receipt_number}.\nالمتبقي على حسابك: {remaining} دينار.\nشكراً لثقتك 🌷\n{company}",
        ],
        'payment.due' => [
            'group' => 'invoice', 'label' => 'تذكير بموعد الدفعة', 'source' => 'invoice', 'priority' => 5,
            'description' => 'قبل موعد استحقاق الفاتورة بثلاثة أيام وفي يومه، إن بقي مبلغ غير مدفوع.',
            'timing' => ['anchor' => 'due_date', 'offsets' => [-3, 0], 'at' => '10:30'], 'expires_minutes' => 600, 'cancel_on_reply' => false,
            'vars' => ['client_name', 'invoice_number', 'remaining', 'due_date', 'company'],
            'template' => "أهلاً {client_name}\nتذكير لطيف: موعد استحقاق المتبقي من فاتورة {invoice_number} يوم {due_date}، والمتبقي {remaining} دينار.\nإن كنت دفعت فتجاهل هذه الرسالة، ولأي استفسار نحن هنا.\n{company}",
        ],
        'trip.reminder' => [
            'group' => 'trip', 'label' => 'تذكير قبل الرحلة', 'source' => 'invoice', 'priority' => 6,
            'description' => 'قبل موعد السفر بثلاثة أيام.',
            'timing' => ['anchor' => 'trip_date', 'offsets' => [-3], 'at' => '11:00'], 'expires_minutes' => 600, 'cancel_on_reply' => false,
            'vars' => ['client_name', 'trip_date', 'destination', 'company'],
            'template' => "أهلاً {client_name} 🌷\nنذكّرك أن موعد رحلتك إلى {destination} يوم {trip_date}.\nجهّز جواز السفر والأوراق المطلوبة، وإن احتجت أي شيء قبل السفر راسلنا هنا.\n{company}",
        ],
        'trip.eve' => [
            'group' => 'trip', 'label' => 'ليلة السفر', 'source' => 'invoice', 'priority' => 6,
            'description' => 'مساء اليوم الذي يسبق السفر.',
            'timing' => ['anchor' => 'trip_date', 'offsets' => [-1], 'at' => '18:00'], 'expires_minutes' => 300, 'cancel_on_reply' => false,
            'vars' => ['client_name', 'trip_date', 'destination', 'company'],
            'template' => "مساء الخير {client_name}\nغداً موعد رحلتك إلى {destination} إن شاء الله. نتمنى لك سفراً آمناً ورحلة مباركة 🤲\nأي استفسار في الطريق، راسلنا هنا.\n{company}",
        ],
        'trip.arrival' => [
            'group' => 'trip', 'label' => 'الاطمئنان بعد الوصول', 'source' => 'invoice', 'priority' => 5,
            'description' => 'بعد يوم من السفر: الاطمئنان على العميل.',
            'timing' => ['anchor' => 'trip_date', 'offsets' => [1], 'at' => '13:00'], 'expires_minutes' => 480, 'cancel_on_reply' => false,
            'vars' => ['client_name', 'destination', 'company'],
            'template' => "أهلاً {client_name} 🌷\nنتمنى أن تكون وصلت بالسلامة إلى {destination}. كيف الأمور معك؟\nإن واجهتك أي مشكلة في السكن أو المواصلات أخبرنا فوراً.\n{company}",
        ],
        'trip.return' => [
            'group' => 'trip', 'label' => 'بعد العودة', 'source' => 'invoice', 'priority' => 4,
            'description' => 'بعد يوم من العودة (لفواتير العروض بتاريخ عودة): الحمد لله على السلامة ورأي العميل.',
            'timing' => ['anchor' => 'return_date', 'offsets' => [1], 'at' => '13:00'], 'expires_minutes' => 1440, 'cancel_on_reply' => false,
            'vars' => ['client_name', 'destination', 'company'],
            'template' => "الحمد لله على السلامة {client_name} 🌷\nنتمنى أن رحلتك إلى {destination} كانت كما تحب. يسعدنا أن نسمع رأيك أو أي ملاحظة لنتحسن.\n{company}",
        ],
        'quote.followup' => [
            'group' => 'quote', 'label' => 'متابعة عرض السعر', 'source' => 'quote', 'priority' => 4,
            'description' => 'بعد يومين من إرسال السعر بلا رد من العميل. تُلغى إن رد.',
            'timing' => ['anchor' => 'priced_at', 'offsets' => [2], 'at' => '12:00'], 'expires_minutes' => 1440, 'cancel_on_reply' => true,
            'vars' => ['client_name', 'request_number', 'company'],
            'template' => "أهلاً {client_name}\nحبينا نطمئن: هل اطلعت على عرض السعر لطلبك رقم {request_number}؟ إن كان عندك أي سؤال أو تحب نعدّل شيئاً، نحن بخدمتك.\n{company}",
        ],
        'lead.followup' => [
            'group' => 'lead', 'label' => 'متابعة يجدولها الموظف', 'source' => 'conversation', 'priority' => 5,
            'description' => 'متابعة بنص الموظف في موعد يختاره من المحادثة. تُلغى إن رد العميل قبلها.',
            'timing' => ['anchor' => 'manual'], 'expires_minutes' => 1440, 'cancel_on_reply' => true,
            'vars' => ['client_name'],
            'template' => '{text}',
        ],
        'client.welcome' => [
            'group' => 'lead', 'label' => 'ترحيب بعميل جديد', 'source' => 'client', 'priority' => 3,
            'description' => 'عند إضافة عميل جديد برقم هاتف: رسالة ترحيب بالرقم الذي سيتواصل منه.',
            'timing' => ['anchor' => 'now', 'delay_minutes' => 10], 'expires_minutes' => 1440, 'cancel_on_reply' => false,
            'vars' => ['client_name', 'company'],
            'template' => "أهلاً وسهلاً {client_name} 🌷\nسعدنا بانضمامك إلى عملاء {company}. هذا رقمنا على واتساب، راسلنا هنا لأي حجز أو استفسار.",
        ],
        'admin.summary' => [
            'group' => 'admin', 'label' => 'ملخص اليوم للإدارة', 'source' => 'admin', 'priority' => 2,
            'description' => 'كل يوم الساعة 20:00 لأرقام الإدارة: محادثات اليوم والفواتير والتحصيل ورسائل النظام.',
            'timing' => ['anchor' => 'daily', 'at' => '20:00'], 'expires_minutes' => 240, 'cancel_on_reply' => false,
            'vars' => ['date', 'conversations', 'needs_attention', 'invoices', 'receipts', 'collected', 'system_sent', 'system_failed'],
            'template' => "📊 ملخص {date}\n💬 محادثات اليوم: {conversations} (تحتاج تدخّلاً: {needs_attention})\n🧾 فواتير معتمدة: {invoices}\n💵 سندات قبض: {receipts} بقيمة {collected} دينار\n📨 رسائل النظام: {system_sent} أُرسلت، {system_failed} لم تُرسل",
        ],
    ];

    public static function exists(string $key): bool
    {
        return isset(self::TYPES[$key]);
    }

    public static function groupOf(string $key): string
    {
        return self::TYPES[$key]['group'] ?? (explode('.', $key)[0] ?: 'lead');
    }

    public static function label(string $key): string
    {
        return self::TYPES[$key]['label'] ?? $key;
    }

    /**
     * التعريف الفعّال: الكود + صف wa_message_types. بلا صف: enabled وauto وtest.
     *
     * @return array<string, mixed>|null
     */
    public static function effective(string $key): ?array
    {
        $def = self::TYPES[$key] ?? null;
        if ($def === null) {
            return null;
        }
        $row = self::rows()[$key] ?? null;

        return ['key' => $key] + [
            'enabled' => $row?->enabled ?? true,
            'mode' => $row?->mode ?: 'auto',
            'stage' => $row?->stage ?: 'test',
            'channel_id' => $row?->channel_id,
            'template' => filled($row?->template) ? (string) $row->template : $def['template'],
            'timing' => array_replace($def['timing'], (array) ($row?->timing ?? [])),
            'priority' => $row?->priority ?? $def['priority'],
            'expires_minutes' => $row?->expires_after_minutes ?? $def['expires_minutes'],
            'cancel_on_reply' => $row?->cancel_on_reply ?? $def['cancel_on_reply'],
            'tests_count' => (int) ($row?->tests_count ?? 0),
            'last_test_at' => $row?->last_test_at,
        ] + $def;
    }

    /** @var array<string, WaMessageType>|null */
    private static ?array $rows = null;

    /** @return array<string, WaMessageType> */
    public static function rows(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }
        if (!Schema::hasTable('wa_message_types')) {
            return self::$rows = [];
        }

        return self::$rows = WaMessageType::query()->get()->keyBy('key')->all();
    }

    public static function flush(): void
    {
        self::$rows = null;
    }
}
