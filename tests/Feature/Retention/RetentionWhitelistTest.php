<?php

namespace Tests\Feature\Retention;

use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Prunable;
use Tests\TestCase;

/** لا يُقلَّم جدول خارج القائمة البيضاء، ولا جدول مالي أبداً (E13.2) */
class RetentionWhitelistTest extends TestCase
{
    private const FINANCIAL = ['journal_entries', 'journal_entry_lines', 'invoices', 'invoice_items', 'receipts', 'disbursements', 'ledger_entries', 'accounts'];

    public function test_every_prunable_model_is_whitelisted_and_none_is_financial(): void
    {
        $listed = config('retention.prunable_models');
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            $traits = class_uses_recursive($class);
            if (isset($traits[Prunable::class]) || isset($traits[MassPrunable::class])) {
                $this->assertContains($class, $listed, "{$class} قابل للتقليم وليس في config('retention.prunable_models')");
            }
        }
        foreach ($listed as $class) {
            $this->assertNotContains((new $class)->getTable(), self::FINANCIAL, "{$class} جدول مالي");
        }
    }
}
