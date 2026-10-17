<?php

namespace App\Services\WhatsApp\Search;

/**
 * فشل استعلام البحث في الرسائل (E30): بدل QueryException التي تحمل SQL بقيمه، أي نص البحث (قد يكون رقم جواز
 * أو هاتفاً)، فلا يصل السجل ولا صفحة الخطأ.
 */
final class SearchFailed extends \RuntimeException {}
