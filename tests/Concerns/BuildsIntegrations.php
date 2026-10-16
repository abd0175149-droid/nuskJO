<?php

namespace Tests\Concerns;

use App\Models\IntegrationEndpoint;
use App\Services\Integrations\DnsResolver;
use App\Services\Integrations\WebhookSigner;

/**
 * التكاملات الصادرة في الاختبارات (E40): وجهة جاهزة، وتحليل DNS ثابت بلا شبكة، والعلم.
 * العناوين من نطاقات التوثيق العامة حين يلزم عنوان «عام» (93.184.216.34 مثال تقليدي).
 */
trait BuildsIntegrations
{
    protected const PUBLIC_IP = '93.184.216.34';

    protected function enableOutbox(): void
    {
        config(['features.env.integration_outbox' => true]);
    }

    /** @param  array<string, list<string>>  $map  مضيف => عناوين؛ مضيف غير مذكور يحل إلى العنوان العام */
    protected function fakeDns(array $map = []): DnsResolver
    {
        $resolver = new class($map, self::PUBLIC_IP) extends DnsResolver
        {
            /** @var list<string> */
            public array $asked = [];

            public function __construct(public array $map, private string $default) {}

            public function resolve(string $host): array
            {
                $this->asked[] = $host;

                return $this->map[$host] ?? [$this->default];
            }
        };
        $this->app->instance(DnsResolver::class, $resolver);

        return $resolver;
    }

    protected function endpoint(array $attributes = []): IntegrationEndpoint
    {
        $endpoint = new IntegrationEndpoint;
        $endpoint->forceFill(array_merge([
            'name' => 'n8n',
            'url' => 'https://hooks.example.com/webhook/nusk',
            'events' => ['quote_request.created', 'quote_request.converted', 'invoice.approved'],
            'include_amounts' => false,
            'secret' => WebhookSigner::generateSecret(),
            'is_active' => true,
        ], $attributes))->save();

        return $endpoint;
    }
}
