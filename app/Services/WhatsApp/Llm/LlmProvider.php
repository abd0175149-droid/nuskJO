<?php

namespace App\Services\WhatsApp\Llm;

/**
 * عقد مزوّد الذكاء — كل ما يلزم لحلقة الأدوات، معزولاً عن المحرّك.
 * استبدال المزوّد لا يمسّ BotEngine ولا الأدوات.
 *
 * التمثيل المحايد للرسائل الذي يبنيه المحرّك:
 *   [ ['role' => 'user'|'assistant', 'text' => '...'], ... ]
 * وكل مزوّد يحوّله لصيغته ويملك سجلّه الخاص بعد ذلك.
 */
interface LlmProvider
{
    /** اسم المزوّد للعرض */
    public function key(): string;

    /** النماذج المتاحة فعلياً لهذا المفتاح — تُجلب حيّاً */
    public function models(#[\SensitiveParameter] string $apiKey): array;

    /** تحويل التمثيل المحايد إلى سجلّ المزوّد */
    public function buildHistory(array $messages): array;

    /**
     * نداء واحد للنموذج. $timeout بالثواني (null: الافتراضي 40)، يحدّده المحرّك من ميزانية الرد.
     * يرمي LlmException مصنَّفاً (LlmErrorClassifier): غير متاح، أو خنق، أو مفتاح/نموذج مرفوض، أو حصة منتهية، أو طلب مرفوض.
     *
     * @return array{text:string, tool_calls:array<array{id:string,name:string,input:array}>,
     *               raw_assistant:mixed, usage:array{in:int,out:int,cache_read:int,cache_write:int}}
     */
    public function generate(#[\SensitiveParameter] string $apiKey, string $model, array $system, array $history, array $tools, ?int $timeout = null): array;

    /** إلحاق ردّ النموذج بالسجلّ — كما وصل حرفياً (مهم لنماذج التفكير) */
    public function appendAssistant(array &$history, mixed $rawAssistant): void;

    /**
     * إلحاق نتائج الأدوات.
     *
     * @param  array<array{id:string,name:string,output:array}>  $results
     */
    public function appendToolResults(array &$history, array $results): void;
}
