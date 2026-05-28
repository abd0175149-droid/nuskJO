# NUSUK - نظام إدارة العمليات المالية والتشغيلية

## نبذة عامة
نظام متكامل لإدارة العمليات المالية بين وكلاء في السعودية (SAR) وعملاء/مكاتب في الأردن (JOD).
يشمل: إدارة الحوالات، المخالفات، الفواتير، التحصيل، المصاريف، مع نظام اعتمادات (Maker-Checker).

## التقنيات المستخدمة
- **Backend**: Laravel 11 (PHP 8.3+)
- **Frontend**: Vue.js 3 + Inertia.js
- **CSS**: Tailwind CSS
- **Database**: PostgreSQL 16
- **PWA**: Service Workers + Web App Manifest
- **Notifications**: Firebase FCM + WebSockets (Laravel Reverb)
- **PDF**: DomPDF / Browsershot
- **Server**: Local server + Cloudflare Tunnel + HTTPS

## العملات
- **الريال السعودي (SAR)**: DECIMAL(15,2) - هللتين
- **الدينار الأردني (JOD)**: DECIMAL(15,3) - 3 خانات (فلس)

## الأدوار
1. **مدير عام (admin)**: صلاحيات كاملة + اعتماد العمليات
2. **موظف مبيعات (sales)**: إدخال عمليات (Pending) بدون تأثير على الأرصدة
3. **محاسب (accountant)**: عمليات مالية محدودة

## نظام الاعتماد (Maker-Checker)
- أي عملية يُدخلها الموظف تبقى بحالة `pending`
- لا تؤثر على الأرصدة إلا بعد اعتماد المدير (`approved`)
- المدير يستطيع الرفض (`rejected`) مع ذكر السبب
