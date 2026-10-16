<?php

namespace App\Services\WhatsApp\Outbound;

/**
 * الرسائل التفاعلية الرسمية على مغلف E32 (E36، OutboundMessage::registerType): أزرار الرد (button) والقائمة (list)
 * والنموذج الثابت (flow بوضع navigate). القص هنا بحدود ميتا وبـ mb_substr وحده (لا trim بقائمة محارف،
 * nuskjo-utf8-trim-bug)، والعناوين المكررة تُميَّز بلاحقة « 2». المعرّفات تأتي من ChoiceBuilder أو من كود ثابت،
 * لا من نص يكتبه النموذج (NoCatalogNoFlowEndpointTest).
 *
 * نص الصف في wa_messages: النص ثم «[قائمة: t1 | t2]» أو «[أزرار: …]» أو «[نموذج: …]»، فيرى النموذج في سياقه ما عُرض
 * بالعناوين وحدها بلا معرّفات. الـ Flow بلا نقطة نهاية: navigate إلى شاشة ثابتة، ولا مسار آخر في الكود.
 * التسجيل كسول ومتكرر الأمان.
 */
final class InteractiveTypes
{
    public const TYPE = 'interactive';

    public const MAX_BUTTONS = 3;

    public const BUTTON_TITLE = 20;

    public const BUTTON_ID = 256;

    public const BUTTON_BODY = 1024;

    public const MAX_ROWS = 10;

    public const MAX_SECTIONS = 10;

    public const ROW_TITLE = 24;

    public const ROW_DESCRIPTION = 72;

    public const ROW_ID = 200;

    public const LIST_BUTTON = 20;

    public const LIST_BODY = 4096;

    public const HEADER = 60;

    public const FOOTER = 60;

    public const FLOW_BODY = 1024;

    public const FLOW_CTA = 30;

    public const FLOW_MODES = ['draft', 'published'];

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered && in_array(self::TYPE, OutboundMessage::types(), true)) {
            return;
        }
        OutboundMessage::registerType(self::TYPE,
            fn (OutboundMessage $m) => (array) ($m->data['interactive'] ?? []),
            fn (OutboundMessage $m) => (string) ($m->data['display'] ?? ''));
        self::$registered = true;
    }

    /**
     * أزرار رد (حتى 3): العنوان 20 حرفاً وغير مكرر، والمعرّف 256، والنص 1024. $opts: header وfooter (نص حتى 60).
     *
     * @param  array<array{id: string, title: string}>  $buttons
     */
    public static function buttons(string $body, array $buttons, array $opts = []): ?OutboundMessage
    {
        $titles = [];
        $ids = [];
        $rows = [];
        foreach ($buttons as $b) {
            $id = mb_substr((string) ($b['id'] ?? ''), 0, self::BUTTON_ID);
            if ($id === '' || isset($ids[$id]) || count($rows) >= self::MAX_BUTTONS) {
                continue;
            }
            $ids[$id] = true;
            $title = self::unique((string) ($b['title'] ?? ''), self::BUTTON_TITLE, $titles);
            $rows[] = ['type' => 'reply', 'reply' => ['id' => $id, 'title' => $title]];
        }
        if ($rows === [] || trim($body) === '') {
            return null;
        }
        $interactive = ['type' => 'button'] + self::header($opts)
            + ['body' => ['text' => mb_substr($body, 0, self::BUTTON_BODY)]] + self::footer($opts)
            + ['action' => ['buttons' => $rows]];

        return self::make($interactive, $body, 'أزرار', array_map(fn ($r) => $r['reply']['title'], $rows));
    }

    /**
     * قائمة: حتى 10 صفوف في المجموع وحتى 10 أقسام. عنوان الصف والقسم 24، والوصف 72، والمعرّف 200، والزر 20، والنص 4096.
     * توقيع قديم متوافق: مصفوفة صفوف مسطحة تُلف في قسم واحد بلا عنوان.
     *
     * @param  array<array{title?: string, rows: array}>|array<array{id: string, title: string, description?: string}>  $sections
     */
    public static function list(string $body, string $buttonLabel, array $sections, array $opts = []): ?OutboundMessage
    {
        if ($sections !== [] && !isset(reset($sections)['rows'])) {
            $sections = [['rows' => $sections]];
        }
        $out = [];
        $ids = [];
        $total = 0;
        $titles = [];
        foreach (array_slice($sections, 0, self::MAX_SECTIONS) as $section) {
            $rows = [];
            foreach ((array) ($section['rows'] ?? []) as $r) {
                $id = mb_substr((string) ($r['id'] ?? ''), 0, self::ROW_ID);
                if ($id === '' || isset($ids[$id]) || $total >= self::MAX_ROWS) {
                    continue;
                }
                $ids[$id] = true;
                $row = ['id' => $id, 'title' => self::unique((string) ($r['title'] ?? ''), self::ROW_TITLE, $titles)];
                $desc = trim((string) ($r['description'] ?? ''));
                if ($desc !== '') {
                    $row['description'] = mb_substr($desc, 0, self::ROW_DESCRIPTION);
                }
                $rows[] = $row;
                $total++;
            }
            if ($rows === []) {
                continue;
            }
            $title = trim((string) ($section['title'] ?? ''));
            $out[] = ($title !== '' ? ['title' => mb_substr($title, 0, self::ROW_TITLE)] : []) + ['rows' => $rows];
        }
        if ($out === [] || trim($body) === '') {
            return null;
        }
        // ميتا تشترط عنواناً لكل قسم إن تعدّدت الأقسام
        if (count($out) > 1) {
            foreach ($out as $i => $s) {
                $out[$i] = ['title' => $s['title'] ?? 'خيارات'] + $s;
            }
        }
        $label = trim($buttonLabel) !== '' ? mb_substr(trim($buttonLabel), 0, self::LIST_BUTTON) : 'الخيارات';
        $interactive = ['type' => 'list'] + self::header($opts)
            + ['body' => ['text' => mb_substr($body, 0, self::LIST_BODY)]] + self::footer($opts)
            + ['action' => ['button' => $label, 'sections' => $out]];

        return self::make($interactive, $body, 'قائمة', array_merge(...array_map(fn ($s) => array_column($s['rows'], 'title'), $out)));
    }

    /**
     * نموذج ثابت (Flow) بوضع navigate إلى شاشته الأولى، بلا نقطة نهاية: flow_message_version=3، والمعرّف flow_id وحده،
     * وflow_token يولّده الخادم ويربطه بالمحادثة، وزر حتى 30 حرفاً. mode: draft للتجربة أو published.
     */
    public static function flow(string $body, string $flowId, string $flowToken, string $cta, string $mode = 'published', string $screen = 'QUOTE'): ?OutboundMessage
    {
        if (!preg_match('/^\d{1,32}$/', $flowId) || !preg_match('/^[A-Za-z0-9]{16,64}$/', $flowToken) || trim($body) === '') {
            return null;
        }
        $cta = mb_substr(trim($cta) !== '' ? trim($cta) : 'فتح النموذج', 0, self::FLOW_CTA);
        $interactive = [
            'type' => 'flow',
            'body' => ['text' => mb_substr($body, 0, self::FLOW_BODY)],
            'action' => ['name' => 'flow', 'parameters' => [
                'flow_message_version' => '3',
                'flow_token' => $flowToken,
                'flow_id' => $flowId,
                'flow_cta' => $cta,
                'flow_action' => 'navigate',
                'flow_action_payload' => ['screen' => $screen],
                'mode' => in_array($mode, self::FLOW_MODES, true) ? $mode : 'published',
            ]],
        ];

        return self::make($interactive, $body, 'نموذج', [$cta]);
    }

    /** نص الصف: النص ثم العناوين وحدها بين قوسين */
    public static function display(string $body, string $label, array $titles): string
    {
        return rtrim($body)."\n[{$label}: ".implode(' | ', $titles).']';
    }

    /** العناوين المعروضة في الخيط من الحمولة المحفوظة، بلا معرّفات @return array{type: string, header: ?string, items: list<array{title: string, description?: string}>}|null */
    public static function shown(array $payload): ?array
    {
        $type = (string) data_get($payload, 'interactive.type', '');
        $items = match ($type) {
            'button' => array_map(fn ($b) => ['title' => (string) data_get($b, 'reply.title', '')], (array) data_get($payload, 'interactive.action.buttons', [])),
            'list' => array_merge([], ...array_map(fn ($s) => array_map(fn ($r) => array_filter([
                'title' => (string) ($r['title'] ?? ''), 'description' => isset($r['description']) ? (string) $r['description'] : null,
            ], fn ($v) => $v !== null), (array) ($s['rows'] ?? [])), (array) data_get($payload, 'interactive.action.sections', []))),
            'flow' => [['title' => (string) data_get($payload, 'interactive.action.parameters.flow_cta', '')]],
            default => null,
        };
        if ($items === null) {
            return null;
        }
        $header = data_get($payload, 'interactive.header.text');

        return ['type' => $type, 'header' => is_string($header) ? $header : null, 'items' => array_values(array_filter($items, fn ($i) => $i['title'] !== ''))];
    }

    private static function make(array $interactive, string $body, string $label, array $titles): OutboundMessage
    {
        self::register();

        return OutboundMessage::custom(self::TYPE, ['interactive' => $interactive, 'display' => self::display($body, $label, $titles)]);
    }

    /** عنوان مقصوص وغير مكرر: الثاني « 2» والثالث « 3» ضمن الحد نفسه */
    private static function unique(string $title, int $max, array &$seen): string
    {
        $title = trim($title) !== '' ? trim($title) : '—';
        $candidate = mb_substr($title, 0, $max);
        for ($n = 2; isset($seen[$candidate]); $n++) {
            $suffix = ' '.$n;
            $candidate = mb_substr($title, 0, $max - mb_strlen($suffix)).$suffix;
        }
        $seen[$candidate] = true;

        return $candidate;
    }

    private static function header(array $opts): array
    {
        $h = trim((string) ($opts['header'] ?? ''));

        return $h !== '' ? ['header' => ['type' => 'text', 'text' => mb_substr($h, 0, self::HEADER)]] : [];
    }

    private static function footer(array $opts): array
    {
        $f = trim((string) ($opts['footer'] ?? ''));

        return $f !== '' ? ['footer' => ['text' => mb_substr($f, 0, self::FOOTER)]] : [];
    }
}
