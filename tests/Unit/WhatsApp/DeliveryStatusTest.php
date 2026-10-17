<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\DeliveryStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * رتب حالات التسليم (E21): كل زوج من الحالة الحالية (الفراغ والحالات الثماني) والواردة (الثماني وقيمة مجهولة)،
 * 81 زوجاً بالناتج المتوقع. أي تعديل في RANK أو allowedFrom يكسر هذا الجدول.
 */
class DeliveryStatusTest extends TestCase
{
    private const INCOMING = ['queued', 'unknown', 'unconfirmed', 'sent', 'delivered', 'read', 'played', 'failed', 'deleted'];

    /** الحالة الحالية => الواردة التي تكتب فوقها (بترتيب INCOMING) */
    private const EXPECTED = [
        'null' => [0, 0, 0, 1, 1, 1, 1, 1, 0],
        'queued' => [0, 0, 0, 1, 1, 1, 1, 1, 0],
        'unknown' => [0, 0, 0, 1, 1, 1, 1, 1, 0],
        'unconfirmed' => [0, 0, 0, 1, 1, 1, 1, 1, 0],
        'sent' => [0, 0, 0, 0, 1, 1, 1, 1, 0],
        'delivered' => [0, 0, 0, 0, 0, 1, 1, 0, 0],
        'read' => [0, 0, 0, 0, 0, 0, 1, 0, 0],
        'played' => [0, 0, 0, 0, 0, 0, 0, 0, 0],
        'failed' => [0, 0, 0, 0, 1, 1, 1, 0, 0],
    ];

    public static function pairs(): array
    {
        $out = [];
        foreach (self::EXPECTED as $current => $row) {
            foreach (self::INCOMING as $i => $incoming) {
                $out["{$current} ← {$incoming}"] = [$current === 'null' ? null : $current, $incoming, (bool) $row[$i]];
            }
        }

        return $out;
    }

    #[DataProvider('pairs')]
    public function test_every_pair_of_current_and_incoming_status(?string $current, string $incoming, bool $advances): void
    {
        $this->assertSame($advances, DeliveryStatus::advances($current, $incoming));
    }

    public function test_the_matrix_is_complete(): void
    {
        $this->assertCount(81, self::pairs());
    }

    public function test_known_values_are_the_ranks_and_failed_only(): void
    {
        foreach (['queued', 'unknown', 'unconfirmed', 'sent', 'delivered', 'read', 'played', 'failed'] as $s) {
            $this->assertTrue(DeliveryStatus::isKnown($s), $s);
        }
        foreach (['deleted', 'warning', 'blocked', 'received', ''] as $s) {
            $this->assertFalse(DeliveryStatus::isKnown($s), $s);
        }
    }

    public function test_positive_evidence_beats_an_earlier_failure_and_a_late_failure_never_hides_delivery(): void
    {
        $this->assertContains('failed', DeliveryStatus::allowedFrom('delivered'));
        $this->assertContains('failed', DeliveryStatus::allowedFrom('read'));
        $this->assertNotContains('failed', DeliveryStatus::allowedFrom('sent'));
        $this->assertSame(['queued', 'unknown', 'unconfirmed', 'sent'], DeliveryStatus::allowedFrom('failed'));
    }

    public function test_a_policy_block_is_never_overwritten_and_rank_zero_is_never_written(): void
    {
        foreach (self::INCOMING as $incoming) {
            $this->assertNotContains('blocked', DeliveryStatus::allowedFrom($incoming));
        }
        $this->assertSame([], DeliveryStatus::allowedFrom('queued'));
        $this->assertSame([], DeliveryStatus::allowedFrom('unknown'));
        $this->assertSame([], DeliveryStatus::allowedFrom('deleted'));
    }

    public function test_arabic_labels(): void
    {
        $this->assertSame(['قيد الإرسال', 'أُرسلت', 'سُلّمت', 'قُرئت', 'سُمعت', 'فشلت', 'غير مؤكدة', 'غير مؤكدة'],
            array_map(DeliveryStatus::label(...), ['queued', 'sent', 'delivered', 'read', 'played', 'failed', 'unknown', 'unconfirmed']));
    }
}
