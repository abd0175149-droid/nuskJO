<?php

namespace Tests\Feature\Security;

use App\Models\AttendanceLocation;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

/**
 * عنوان الزائر: X-Forwarded-For يُقبل من الوكلاء في TRUSTED_PROXIES وحدهم، وما سواه يُسجَّل.
 * والحضور بالشبكة يقبل عنواناً مفرداً أو نطاق CIDR.
 */
class TrustedProxiesTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_test/ip', fn () => request()->ip());
    }

    private function ipFrom(string $remote): string
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remote])
            ->get('/_test/ip', ['X-Forwarded-For' => '203.0.113.9'])->getContent();
    }

    public function test_default_keeps_trusting_any_source_until_configured(): void
    {
        $this->assertSame('*', config('trustedproxy.proxies'));
        $this->assertSame('203.0.113.9', $this->ipFrom('172.18.0.1'));
    }

    public function test_configured_proxies_alone_may_set_the_client_address(): void
    {
        config(['trustedproxy.proxies' => '127.0.0.1,172.18.0.1/32']);
        $this->captureLogs();

        $this->assertSame('203.0.113.9', $this->ipFrom('172.18.0.1'));
        $this->assertSame('10.1.2.3', $this->ipFrom('10.1.2.3'));
        $this->assertLogged('warning', 'proxy.untrusted_forwarded', ['remote' => '10.1.2.3']);
    }

    public function test_attendance_networks_accept_single_addresses_and_cidr_ranges(): void
    {
        AttendanceLocation::create(['name' => 'المكتب', 'type' => 'ip', 'ip_range' => '203.0.113.0/28, 198.51.100.7', 'is_active' => true]);
        AttendanceLocation::create(['name' => 'قديم', 'type' => 'ip', 'ip_range' => '192.0.2.50', 'is_active' => false]);
        $service = app(AttendanceService::class);

        $this->assertTrue($service->validateIp('203.0.113.9'));
        $this->assertTrue($service->validateIp('198.51.100.7'));
        $this->assertFalse($service->validateIp('203.0.113.200'));
        $this->assertFalse($service->validateIp('192.0.2.50'), 'الموقع المعطّل لا يُقبل');
        $this->assertFalse($service->validateIp(''));
    }
}
