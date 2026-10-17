// مرفقات العملاء في الصندوق (E14): الحجم المقروء وتسمية الحالة. بلا DOM، فتُختبر بـ node --test

export const KIND_ICONS = { image: '📷', document: '📄', video: '🎬', audio: '🎤', sticker: '🏷️' };

export function formatSize(bytes) {
  if (bytes === null || bytes === undefined || Number.isNaN(Number(bytes))) return '';
  const n = Number(bytes);
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`;
  return `${(n / (1024 * 1024)).toFixed(n < 10 * 1024 * 1024 ? 1 : 0)} MB`;
}

const ERRORS = {
  131052: 'الملف أكبر من 100MB أو تعذّر تنزيله عند ميتا',
  META_NOT_FOUND: 'انتهت صلاحيته عند ميتا',
  MEDIA_HOST_BLOCKED: 'رابط ميتا من مضيف غير متوقع',
  MEDIA_INTEGRITY: 'الملف لا يطابق بصمته',
  RETRY_EXPIRED: 'نفدت المحاولات',
};

// null: لا حالة أرشفة (العلم مطفأ أو رسالة قديمة)
export function statusLabel(media) {
  if (!media || !media.status) return null;
  switch (media.status) {
    case 'pending': return 'جارٍ حفظ الملف…';
    case 'stored': return null;
    case 'too_large': return `أكبر من الحد${media.size ? ` (${formatSize(media.size)})` : ''}`;
    case 'failed': return `تعذّر الحفظ${ERRORS[media.error] ? ` — ${ERRORS[media.error]}` : ''}`;
    case 'expired': return 'انتهت صلاحيته عند ميتا قبل الحفظ';
    case 'rejected': return 'نوع ملف غير مسموح';
    case 'purged': return 'حُذف بانتهاء مدة الاحتفاظ';
    case 'missing': return 'الملف غير موجود على الخادم';
    case 'skipped_quota': return 'لم يُحفظ (الأرشيف بلغ سقفه)';
    case 'skipped_consent': return 'لم يُحفظ (طلب العميل حذف ملفاته)';
    default: return null;
  }
}

export function mediaUrl(messageId, download = false) {
  return `/api/whatsapp/messages/${encodeURIComponent(messageId)}/media${download ? '?download=1' : ''}`;
}

// رسالة الخطأ عند العرض أو التنزيل
export function fetchError(status) {
  if (status === 403) return 'لا تملك صلاحية عرض المرفقات';
  if (status === 404) return 'الملف غير متاح لك أو حُذف';
  return 'تعذّر جلب الملف — حاول ثانية';
}
