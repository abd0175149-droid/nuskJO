<?php

namespace App\Services\WhatsApp\Bot;

use App\Models\WaBotSetting;
use Illuminate\Support\Facades\Schema;

/**
 * أي أداة يُعلنها البوت لهذه المحادثة: ملف المرسل (SenderProfile) × وضع البوت على رقم المحادثة (wa_channels.bot_mode).
 * المصفوفة الافتراضية DEFAULTS في الكود، وتعديلات المدير في wa_bot_settings.tool_profiles ({أداة: {ملف: bool}}).
 * - bot_mode=limited (رقم الإشعارات مثلاً): أدوات LIMITED وحدها، فيحوّل البوت لموظف ولا يبيع.
 * - handoff_to_human لا تُطفأ أبداً لأي ملف ولا وضع.
 * - ملف admin لا يمر من هنا: المساعد الإداري وأدواته (AdminTools) بصلاحيات رقمه.
 * الإعلان وحده لا يكفي: ToolInvoker يرفض أداة غير معلنة لهذه المحادثة، فلا تُنفَّذ أداة محجوبة ولو طلبها النموذج.
 */
final class ToolPolicy
{
    public const ALWAYS = ['handoff_to_human'];

    public const LIMITED = ['handoff_to_human', 'set_topic', 'save_note', 'send_office_location', 'send_contact_card'];

    private const ALL = ['guest', 'lead', 'client', 'traveler'];

    private const LINKED = ['client', 'traveler'];

    /** @var array<string, list<string>> الأداة => الملفات المسموحة افتراضياً */
    public const DEFAULTS = [
        'get_offers' => self::ALL,
        'get_offer_details' => self::ALL,
        'send_offer_card' => self::ALL,
        'set_topic' => self::ALL,
        'get_my_balance' => self::LINKED,
        'get_my_invoices' => self::LINKED,
        'get_my_trips' => self::LINKED,
        'request_quote' => self::ALL,
        'confirm_booking' => self::ALL,
        'save_note' => self::ALL,
        'send_office_location' => self::ALL,
        'send_contact_card' => self::ALL,
        'show_offers_menu' => self::ALL,
        'present_choices' => self::ALL,
        'handoff_to_human' => self::ALL,
        'get_my_quote_status' => ['lead', 'client', 'traveler'],
        'resend_invoice_pdf' => self::LINKED,
        'get_trip_details' => self::LINKED,
        'report_issue' => self::LINKED,
        'manage_my_notifications' => ['lead', 'client', 'traveler'],
    ];

    public static function allowed(string $tool, string $profile, string $botMode = 'full'): bool
    {
        if (in_array($tool, self::ALWAYS, true)) {
            return $botMode !== 'off';
        }
        if ($botMode === 'off' || $profile === 'admin') {
            return false;
        }
        if ($botMode === 'limited' && !in_array($tool, self::LIMITED, true)) {
            return false;
        }

        return self::matrix()[$tool][$profile] ?? false;
    }

    /** @return array<string, array<string, bool>> المصفوفة الفعّالة (الكود + تعديلات المدير) */
    public static function matrix(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $tool => $profiles) {
            foreach (self::ALL as $p) {
                $out[$tool][$p] = in_array($p, $profiles, true);
            }
        }
        foreach (self::overrides() as $tool => $row) {
            if (!isset($out[$tool]) || in_array($tool, self::ALWAYS, true)) {
                continue;
            }
            foreach ((array) $row as $p => $v) {
                if (in_array($p, self::ALL, true)) {
                    $out[$tool][$p] = (bool) $v;
                }
            }
        }

        return $out;
    }

    /** @return array<string, array<string, bool>> */
    public static function overrides(): array
    {
        if (!Schema::hasColumn('wa_bot_settings', 'tool_profiles')) {
            return [];
        }
        $raw = WaBotSetting::current()->tool_profiles;
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($raw) ? $raw : [];
    }
}
