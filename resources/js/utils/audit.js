// تسميات سجل التدقيق المشتركة بين صفحة السجل ومكوّن «تاريخ الكيان»
export const ENTITY = {
    User: 'مستخدم', Role: 'دور', Setting: 'إعداد', setting: 'إعداد', BackupRun: 'نسخة احتياطية',
    invoice: 'فاتورة', receipt: 'سند قبض', disbursement: 'سند صرف', transfer: 'تحويل', expense: 'مصروف',
    payroll: 'مسير رواتب', advance: 'سلفة', account: 'حساب', journal_entry: 'قيد', journal_entry_line: 'سطر قيد',
    fiscal_year: 'سنة مالية', accounting_period: 'فترة محاسبية', exchange_rate: 'سعر صرف',
    offer: 'عرض', quote_request: 'طلب تسعير',
    wa_bot_settings: 'إعدادات البوت', wa_conversation: 'محادثة واتساب', wa_knowledge_entry: 'معلومة للبوت',
    wa_admin_number: 'رقم إدارة', wa_routing: 'توجيه المحادثات', wa_account: 'حساب ميتا', webhook: 'ويبهوك',
    route: 'مسار', audit_log: 'سجل التدقيق', system: 'النظام',
};

export const entityLabel = (type) => ENTITY[type] || type;

export const when = (iso) => {
    if (!iso) return '—';
    const d = new Date(iso);
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
};
