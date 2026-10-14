<?php

namespace App\Services\WhatsApp\Media;

/** يُرمى من on_headers حين يعلن Content-Length حجماً فوق السقف، فيُقطع التنزيل قبل الجسم */
final class MediaTooLarge extends \RuntimeException {}
