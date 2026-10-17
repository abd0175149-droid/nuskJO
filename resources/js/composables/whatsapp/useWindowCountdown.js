// نافذة الرد 24 ساعة عند ميتا: الوقت الباقي مقروءاً ودرجة الإلحاح (E16)

/** «5س 20د» أو «40د»؛ السالب والفارغ صفر */
export function windowLabel(mins) {
    const total = Math.max(0, Math.floor(Number(mins) || 0));
    const h = Math.floor(total / 60);
    const m = total % 60;
    return h ? `${h}س${m ? ' ' + m + 'د' : ''}` : `${m}د`;
}

/** ok، أو warn (6 ساعات فأقل)، أو danger (ساعتان فأقل)؛ null إن لم يُعرف الباقي */
export function urgency(mins) {
    if (mins === null || mins === undefined) return null;
    if (mins <= 120) return 'danger';
    if (mins <= 360) return 'warn';
    return 'ok';
}

export function closed(windowOpen) {
    return !windowOpen;
}
