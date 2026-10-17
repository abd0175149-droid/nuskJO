<?php

namespace App\Events\WhatsApp;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * تغيّرت حالة قناة الإرسال فعلاً. يُطلق من App\Services\WhatsApp\ChannelHealth وحدها (E22)، مرة لكل تغيّر:
 * - انتقال آلة الحالات (العلم channel_health): from وto من WaHealth (AVAILABLE وLIMITED وBLOCKED وTOKEN_INVALID وUNKNOWN)،
 *   وsource من HealthWriter (webhook أو poll أو send_error أو token_check أو manual).
 * - المفتاح اليدوي (E10): SUSPENDED_MANUAL في طرف، وفي الآخر حالة الآلة (أو AVAILABLE والعلم مطفأ)، وsource=manual.
 * - الإيقاف الآلي القديم (العلم مطفأ): AVAILABLE إلى SUSPENDED_AUTO، وsource meta_event أو graph_error.
 * reasonCode رمز داخلي بلا نص (ban:DISABLE، integrity:368، token:190، رمز ميتا، أو المصدر السابق عند الاستئناف).
 */
final class ChannelHealthChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const AVAILABLE = 'AVAILABLE';

    public const SUSPENDED_AUTO = 'SUSPENDED_AUTO';

    public const SUSPENDED_MANUAL = 'SUSPENDED_MANUAL';

    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $source,
        public readonly ?string $reasonCode,
    ) {}
}
