<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaErasureRequest;
use App\Services\Retention\SubjectExport;
use App\Services\WhatsApp\PhoneNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * حق الاطّلاع (E13.8): نسخة من بيانات الشخص إلى storage/app/exports بصلاحية 0600 خارج public، تُحذف بعد
 * 7 أيام (ops:prune-cache). التسليم لصاحب البيانات وحده بعد التحقق، وطريقته قرار المالك. سطر الأوامر فقط.
 */
class ExportClientData extends Command
{
    protected $signature = 'nusk:export-client {--client= : رقم العميل} {--phone= : رقم الهاتف} {--request= : طلب حذف أو اطّلاع} {--format=json : json|html}';

    protected $description = 'تصدير بيانات عميل لحق الاطّلاع (قوائم حقول مسموحة)';

    public function handle(SubjectExport $export): int
    {
        $phone = $this->option('phone') ? (PhoneNormalizer::canonical((string) $this->option('phone')) ?: null) : null;
        $requestId = $this->option('request');
        if ($requestId && ($r = WaErasureRequest::find($requestId)) && $r->conversation?->phone) {
            $phone ??= PhoneNormalizer::canonical($r->conversation->phone);
        }
        $client = SubjectExport::resolveClient($this->option('client'), $phone);
        if (!$client && !$phone) {
            $this->error('حدّد --client أو --phone أو --request.');

            return self::FAILURE;
        }
        $format = $this->option('format') === 'html' ? 'html' : 'json';

        ['data' => $data, 'counts' => $counts] = $export->build($client, $phone);
        $body = $format === 'html'
            ? view('exports.subject', ['data' => $data])->render()
            : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $dir = storage_path('app/exports');
        $old = umask(0077);
        try {
            File::ensureDirectoryExists($dir, 0700);
            $path = $dir.'/'.Str::uuid().'.'.$format;
            File::put($path, $body);
            chmod($path, 0600);
        } finally {
            umask($old);
        }

        AuditLog::log(AuditAction::ClientDataExport, 'client', $client?->id, null, ['counts' => $counts, 'request_id' => $requestId ? (int) $requestId : null, 'format' => $format]);
        $this->info($path);
        $this->line('sha256: '.hash_file('sha256', $path));

        return self::SUCCESS;
    }
}
