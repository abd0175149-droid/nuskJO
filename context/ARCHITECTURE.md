# Architecture - NUSUK System

## هيكلية المجلدات (Laravel 11 + Inertia + Vue 3)

```
NUSUK/
├── app/
│   ├── Enums/              # Status enums (ApprovalStatus, BillingStatus, etc.)
│   ├── Events/             # Laravel events (TransferApproved, InvoiceApproved, etc.)
│   ├── Http/
│   │   ├── Controllers/    # Resource controllers per module
│   │   ├── Middleware/      # Locale, Theme, CheckPermission
│   │   └── Requests/       # Form Request validation classes
│   ├── Listeners/          # Event listeners (UpdateBalance, SendNotification, etc.)
│   ├── Models/             # Eloquent models
│   ├── Notifications/      # Laravel notification classes (FCM + Database)
│   ├── Observers/          # Model observers (auto-numbering, activity logging)
│   ├── Policies/           # Authorization policies per model
│   ├── Services/           # Business logic services
│   │   ├── ApprovalService.php
│   │   ├── BalanceService.php
│   │   ├── ExchangeRateService.php
│   │   ├── InvoiceService.php
│   │   ├── LedgerService.php
│   │   └── NumberingService.php
│   └── Traits/             # Shared traits (HasApproval, HasAttachments, Auditable)
├── resources/
│   ├── js/
│   │   ├── app.js
│   │   ├── Components/     # Vue reusable components
│   │   │   ├── UI/         # Button, Modal, Table, Input, Select, etc.
│   │   │   ├── Layout/     # AppLayout, Sidebar, Navbar, Footer
│   │   │   └── Shared/     # StatusBadge, CurrencyDisplay, ApprovalActions
│   │   ├── Pages/          # Inertia pages (one per route)
│   │   │   ├── Dashboard/
│   │   │   ├── Agents/
│   │   │   ├── Clients/
│   │   │   ├── Transfers/
│   │   │   ├── Receipts/
│   │   │   ├── Violations/
│   │   │   ├── Invoices/
│   │   │   ├── Expenses/
│   │   │   ├── Reports/
│   │   │   └── Settings/
│   │   ├── Composables/    # Vue composables (usePermission, useCurrency, useLocale)
│   │   └── Stores/         # Pinia stores if needed
│   ├── css/
│   │   └── app.css         # Tailwind directives + custom styles
│   └── views/
│       └── app.blade.php   # Inertia root template
├── routes/
│   ├── web.php             # Inertia routes
│   └── api.php             # API routes (if needed for mobile/PWA)
├── database/
│   ├── migrations/         # Laravel migrations (ordered)
│   ├── seeders/            # Default roles, permissions, settings
│   └── factories/          # Testing factories
├── public/
│   ├── manifest.json       # PWA manifest
│   ├── sw.js               # Service Worker
│   └── icons/              # PWA icons
├── config/
│   └── nusuk.php           # App-specific config (currencies, number formats)
└── lang/
    ├── ar/                 # Arabic translations
    └── en/                 # English translations
```

## الطبقات المعمارية

```
[Vue 3 Pages] ←→ [Inertia.js] ←→ [Controllers]
                                       ↓
                                  [Form Requests] (Validation)
                                       ↓
                                  [Policies] (Authorization)
                                       ↓
                                  [Services] (Business Logic)
                                       ↓
                                  [Models + Observers] (Data Layer)
                                       ↓
                                  [Events → Listeners] (Side Effects)
```

## أنماط التصميم المستخدمة

1. **Service Layer**: كل منطق الأعمال في Services (ليس في Controllers)
2. **Observer Pattern**: الترقيم التلقائي + Activity Log
3. **Event-Driven**: عند الاعتماد → Event → Listeners (تحديث أرصدة، إشعارات، سجل)
4. **Trait Composition**: `HasApproval`, `HasAttachments`, `Auditable`
5. **Policy-based Auth**: صلاحيات دقيقة لكل عملية CRUD + approve/reject

## تدفق الاعتماد (Approval Flow)

```
موظف يُدخل عملية → status = 'pending'
                        ↓
              إشعار Push للمدير (FCM)
                        ↓
         المدير يعتمد (approve) أو يرفض (reject)
                        ↓
              [إذا approved]:
                → Event fired (e.g., TransferApproved)
                → Listener: BalanceService->updateBalance()
                → Listener: LedgerService->createEntry()
                → Listener: NotificationService->notifyCreator()
```
