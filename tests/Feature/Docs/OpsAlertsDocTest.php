<?php

namespace Tests\Feature\Docs;

use Tests\TestCase;

/**
 * docs/whatsapp/ops-alerts.md وdocs/ops/load-budget.md (E24): مصفوفة القواعد والميزانية في الوثيقتين تطابق
 * config/ops.php، فلا تتغيّر عتبة في الكود وتبقى الوثيقة على القديمة.
 */
class OpsAlertsDocTest extends TestCase
{
    public function test_every_rule_and_its_threshold_is_documented(): void
    {
        $doc = (string) file_get_contents(base_path('docs/whatsapp/ops-alerts.md'));

        foreach (config('ops.rules') as $rule => $def) {
            $row = collect(explode("\n", $doc))->first(fn ($line) => str_starts_with($line, "| `{$rule}`"));
            $this->assertNotNull($row, "القاعدة {$rule} غائبة عن مصفوفة الوثيقة");
            $this->assertStringContainsString('«'.$def['threshold'].'»', $row, "عتبة {$rule} في الوثيقة لا تطابق config/ops.php");
            $this->assertStringContainsString("| {$def['severity']} |", $row, "خطورة {$rule}");
        }
        $this->assertStringContainsString('«'.config('ops.slow_ms').'»', $doc);
    }

    public function test_the_load_budget_numbers_match_the_config(): void
    {
        $doc = (string) file_get_contents(base_path('docs/ops/load-budget.md'));

        foreach (config('ops.load_budget') as $key => $value) {
            $this->assertMatchesRegularExpression('/«'.$value.'».*`ops\.load_budget\.'.$key.'`/u', $doc, $key);
        }
    }
}
