<?php

namespace App\Services\WhatsApp\Search;

use App\Services\WhatsApp\TextNormalizer;
use Illuminate\Validation\ValidationException;

/**
 * استعلام البحث في الرسائل (E30.3) بعد التطبيع: كلمات حتى 6، وكل كلمة من 3 أحرف فأكثر عبارة FTS5 بين علامتي تنصيص
 * (فلا تعمل NOT وOR وNEAR و* و" مشغّلات)، والأقصر شرط LIKE على body_norm. «ال» الملتصقة (وال، بال، فال، كال، لل)
 * تُحذف من أول كلمة الاستعلام وحده إن بقي 3 أحرف، فـ«العمره» تجد «عمرة» و«العمرة»، ولا يتغيّر النص المخزّن.
 */
final class SearchQuery
{
    public const MAX_CHARS = 100;

    public const MAX_TOKENS = 6;

    /** أقصر جزء يطابقه مُجزِّئ trigram */
    public const MIN_TERM = 3;

    private const ARTICLES = ['وال', 'بال', 'فال', 'كال', 'لل', 'ال'];

    /**
     * @param  list<string>  $terms  كلمات FTS (3 أحرف فأكثر بعد حذف «ال»)
     * @param  list<string>  $short  الأقصر: LIKE دائماً
     * @param  list<string>  $highlight  ما يُبرز في المقتطف: الكلمة كما كُتبت وصيغتها بلا «ال»
     */
    private function __construct(
        public readonly string $norm,
        public readonly array $terms,
        public readonly array $short,
        public readonly array $highlight,
    ) {}

    /** $scoped: داخل محادثة واحدة، فيكفي حرفان (LIKE على رسائلها وحدها) */
    public static function parse(string $q, bool $scoped): self
    {
        $norm = (string) TextNormalizer::forSearch($q);
        if ($norm === '') {
            throw ValidationException::withMessages(['q' => 'اكتب نصاً للبحث']);
        }
        if (mb_strlen($norm) > self::MAX_CHARS) {
            throw ValidationException::withMessages(['q' => 'نص البحث أطول من '.self::MAX_CHARS.' حرف']);
        }

        $tokens = array_slice(array_values(array_unique(preg_split('/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [])), 0, self::MAX_TOKENS);
        $terms = $short = $highlight = [];
        foreach ($tokens as $token) {
            $term = self::stripArticle($token);
            if (mb_strlen($term) >= self::MIN_TERM) {
                $terms[] = $term;
            } else {
                $short[] = $token;
            }
            array_push($highlight, $token, $term);
        }
        $terms = array_values(array_unique($terms));

        $length = mb_strlen(implode(' ', $tokens));
        if (!$scoped && ($terms === [] || $length < self::MIN_TERM)) {
            throw ValidationException::withMessages(['q' => 'اكتب 3 أحرف على الأقل في كلمة واحدة، أو ابحث داخل محادثة واحدة']);
        }
        if ($scoped && $length < 2) {
            throw ValidationException::withMessages(['q' => 'اكتب حرفين على الأقل']);
        }

        return new self($norm, $terms, $short, array_values(array_unique(array_filter($highlight, fn ($t) => $t !== ''))));
    }

    /** عبارات FTS5 منصّصة يجمعها AND ضمني، أو null إن لم توجد كلمة من 3 أحرف */
    public function match(): ?string
    {
        return $this->terms === [] ? null : implode(' ', array_map(fn ($t) => '"'.str_replace('"', '""', $t).'"', $this->terms));
    }

    /** @return list<string> شروط LIKE: الأقصر وحده مع الفهرس، وكل الكلمات بدونه */
    public function likeTerms(bool $withFts): array
    {
        return $withFts ? $this->short : [...$this->terms, ...$this->short];
    }

    /** بصمة تكفي لمطابقة شكوى محددة، بلا نص الاستعلام (قد يكون رقم جواز أو هاتفاً) */
    public function fingerprint(): string
    {
        return substr(sha1($this->norm), 0, 12);
    }

    public function length(): int
    {
        return mb_strlen($this->norm);
    }

    private static function stripArticle(string $token): string
    {
        foreach (self::ARTICLES as $article) {
            if (str_starts_with($token, $article) && mb_strlen($token) - mb_strlen($article) >= self::MIN_TERM) {
                return mb_substr($token, mb_strlen($article));
            }
        }

        return $token;
    }
}
