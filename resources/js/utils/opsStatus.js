// صفحة «حالة النظام» (E23): تسميات المستويات والأعمار، مشتركة بين الصفحة واختباراتها

import { TONES } from '@/utils/channelHealth.js';

export const LEVELS = {
    ok: { label: 'سليم', icon: '🟢', tone: 'green' },
    warn: { label: 'تحذير', icon: '🟠', tone: 'amber' },
    error: { label: 'خطأ', icon: '🔴', tone: 'red' },
    unknown: { label: 'غير معروف', icon: '⚪', tone: 'gray' },
};

export const level = (value) => LEVELS[value] || LEVELS.unknown;
export const levelClass = (value) => TONES[level(value).tone];

// ثوانٍ إلى نص قصير
export function age(seconds) {
    if (seconds === null || seconds === undefined) return '—';
    if (seconds < 60) return `${seconds} ث`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)} د`;
    if (seconds < 172800) return `${Math.floor(seconds / 3600)} س`;
    return `${Math.floor(seconds / 86400)} يوم`;
}

export const QUEUE_LABELS = { default: 'العام', 'wa-replies': 'ردود البوت', 'wa-media': 'أرشيف المرفقات', 'wa-media-out': 'ملفات الموظفين' };

export const BACKUP_ITEMS = { snapshot: 'آخر نسخة', collected: 'السحب المشفّر', pulled: 'جهاز المالك', drill: 'تمرين الاستعادة' };
