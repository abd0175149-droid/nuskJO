<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\Search\SearchQuery;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * استعلام البحث (E30.3): التطبيع، وتنصيص كل كلمة فلا تعمل كلمات FTS5 مشغّلات، و«ال» الملتصقة تُحذف من الاستعلام،
 * والقصير شرط LIKE داخل محادثة واحدة فقط.
 */
class SearchQueryTest extends TestCase
{
    public function test_terms_are_normalised_quoted_and_joined(): void
    {
        $q = SearchQuery::parse('العُمرة   والفندق', false);

        $this->assertSame(['عمره', 'فندق'], $q->terms);
        $this->assertSame('"عمره" "فندق"', $q->match());
        $this->assertSame([], $q->likeTerms(true));
        $this->assertSame(['عمره', 'فندق'], $q->likeTerms(false));
        $this->assertSame(['العمره', 'عمره', 'والفندق', 'فندق'], $q->highlight);
    }

    public function test_fts_syntax_is_neutralised(): void
    {
        $q = SearchQuery::parse('NOT OR NEAR "x" * abc"def', false);

        $this->assertSame('"not" "near" """x""" "abc""def"', $q->match());
        $this->assertSame(['or', '*'], $q->short);
    }

    public function test_an_article_is_kept_when_too_little_would_remain(): void
    {
        $this->assertSame(['الف'], SearchQuery::parse('الف', false)->terms);
        $this->assertSame(['لله'], SearchQuery::parse('لله', false)->terms);
    }

    public function test_short_queries_need_a_single_conversation(): void
    {
        $scoped = SearchQuery::parse('رح', true);
        $this->assertNull($scoped->match());
        $this->assertSame(['رح'], $scoped->likeTerms(true));

        foreach (['رح', 'ab cd', 'ال'] as $q) {
            try {
                SearchQuery::parse($q, false);
                $this->fail("{$q} قُبل بلا محادثة");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('3 أحرف', $e->errors()['q'][0]);
            }
        }
        $this->expectException(ValidationException::class);
        SearchQuery::parse('ـ', true);
    }

    public function test_at_most_six_tokens_and_a_fingerprint_without_the_text(): void
    {
        $q = SearchQuery::parse('one two three four five six seven', false);

        $this->assertSame(['one', 'two', 'three', 'four', 'five', 'six'], [...$q->terms, ...$q->short]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $q->fingerprint());
        $this->assertSame(SearchQuery::parse('ONE two three four five six seven', false)->fingerprint(), $q->fingerprint());
    }
}
