<?php

namespace App\Services\WhatsApp\Outbound;

use App\Models\WaContactCard;
use App\Models\WaPlace;
use Illuminate\Support\Collection;

/**
 * أنواع E33 على مغلف E32 (OutboundMessage::registerType): التفاعل والموقع وبطاقات الاتصال. البيانات من صفوف الجدولين
 * المعتمدين وحدها، فلا تقبل هذه الدوال إحداثيات أو أرقاماً حرة. التسجيل كسول ومتكرر الأمان.
 */
final class RichTypes
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered && in_array('reaction', OutboundMessage::types(), true)) {
            return;
        }
        // التفاعل لا يُرسل مقتبساً (لا context)، والسلسلة الفارغة تزيل تفاعلاً سابقاً
        OutboundMessage::registerType('reaction',
            fn (OutboundMessage $m) => ['message_id' => (string) ($m->data['message_id'] ?? ''), 'emoji' => (string) ($m->data['emoji'] ?? '')],
            fn (OutboundMessage $m) => ($m->data['emoji'] ?? '') !== '' ? (string) $m->data['emoji'] : '↩️ أُزيل التفاعل');
        OutboundMessage::registerType('location',
            fn (OutboundMessage $m) => (array) ($m->data['location'] ?? []),
            fn (OutboundMessage $m) => '📍 '.(string) data_get($m->data, 'location.name', 'موقع'));
        // contacts قائمة بطاقات: payload() يضعها تحت المفتاح contacts كما تطلب ميتا
        OutboundMessage::registerType('contacts',
            fn (OutboundMessage $m) => array_values((array) ($m->data['contacts'] ?? [])),
            fn (OutboundMessage $m) => '👤 بطاقة: '.implode('، ', array_map(fn ($c) => (string) data_get($c, 'name.formatted_name', '—'), (array) ($m->data['contacts'] ?? []))));
        self::$registered = true;
    }

    public static function reaction(string $targetWamid, string $emoji): OutboundMessage
    {
        self::register();

        return OutboundMessage::custom('reaction', ['message_id' => $targetWamid, 'emoji' => $emoji]);
    }

    public static function location(WaPlace $place): OutboundMessage
    {
        self::register();

        return OutboundMessage::custom('location', ['location' => $place->toPayload()]);
    }

    /** @param  Collection<int, WaContactCard>  $cards */
    public static function contacts(Collection $cards): OutboundMessage
    {
        self::register();

        return OutboundMessage::custom('contacts', ['contacts' => $cards->map(fn (WaContactCard $c) => $c->toPayload())->values()->all()]);
    }
}
