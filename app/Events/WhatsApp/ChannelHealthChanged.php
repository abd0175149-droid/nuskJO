<?php

namespace App\Events\WhatsApp;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * تغيّرت حالة قناة الإرسال فعلاً. اليوم: AVAILABLE وSUSPENDED_AUTO وSUSPENDED_MANUAL. source بمفردات
 * wa_suspended_source: meta_event (حدث حساب أو رمز ميتا مع meta_error_v2) أو graph_error (المسار القديم) أو manual.
 * reasonCode رمز ميتا أو اسم الحدث، بلا نص. E22 تنقل الإطلاق إلى ChannelHealth::transition.
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
