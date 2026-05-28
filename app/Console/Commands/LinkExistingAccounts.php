<?php

namespace App\Console\Commands;

use App\Services\AccountLinkService;
use Illuminate\Console\Command;

class LinkExistingAccounts extends Command
{
    protected $signature = 'accounting:link-existing';
    protected $description = 'إنشاء حسابات محاسبية لجميع الوكلاء والعملاء الحاليين';

    public function handle()
    {
        $this->info('بدء ربط الحسابات...');
        
        $stats = AccountLinkService::linkExistingEntities();
        
        $this->info("✅ تم ربط {$stats['agents']} وكيل و {$stats['clients']} عميل بحساباتهم المحاسبية");
        
        return 0;
    }
}
