# Coding Conventions - NUSUK System

---

## 1. أنماط الترقيم التلقائي (Auto-Numbering)

| الكيان | النمط | مثال |
|--------|-------|------|
| الوكلاء | `AGT-XXX` | AGT-001 |
| العملاء | `CLT-XXX` | CLT-001 |
| الخدمات | `SRV-XXX` | SRV-001 |
| أنواع المخالفات | `VLT-XXX` | VLT-001 |
| الحوالات | `TRF-YYYYMMDD-XXXX` | TRF-20260518-0001 |
| سندات القبض | `RCP-YYYYMMDD-XXXX` | RCP-20260518-0001 |
| المصاريف | `EXP-YYYYMMDD-XXXX` | EXP-20260518-0001 |
| المخالفات | `VIO-YYYYMMDD-XXXX` | VIO-20260518-0001 |
| الفواتير | `INV-YYYYMMDD-XXXX` | INV-20260518-0001 |

## 2. العملات

| العملة | الكود | الخانات العشرية | الدولة |
|--------|-------|-----------------|--------|
| ريال سعودي | SAR | 2 | SA |
| دينار أردني | JOD | 3 | JO |

### قواعد العملة:
- يُحدد تلقائياً من الدولة (SA → SAR, JO → JOD)
- `DECIMAL(15,2)` للـ SAR
- `DECIMAL(15,3)` للـ JOD
- **ممنوع FLOAT** للحقول المالية

## 3. سعر الصرف
- يُدخل يومياً: `sar_to_jod` (مثلاً 0.078)
- `jod_to_sar` يُحسب تلقائياً: `1 / sar_to_jod`
- يُجمّد (snapshot) داخل الفاتورة عند الاعتماد

## 4. نظام Maker-Checker
- كل عملية مالية تبدأ بحالة `pending`
- المدير فقط يستطيع `approve` أو `reject`
- عند الاعتماد: تُحدّث الأرصدة عبر `BalanceService`
- عند الرفض: تُسجل `rejection_reason`

## 5. حالات الفوترة (billing_status) - خاص بالمخالفات
| الحالة | المعنى |
|--------|--------|
| `unbilled` | مخالفة معتمدة لم تُفوتر بعد |
| `billed` | أُدرجت في فاتورة |

## 6. أنماط الكود

### Backend (Laravel/PHP):
- **Controllers**: ResourceController (index, store, update, destroy)
- **Models**: $fillable + $casts + relationships
- **Services**: Static methods (BalanceService::creditAgent)
- **Validation**: inline في Controller

### Frontend (Vue 3 + Inertia):
- **Pages**: `resources/js/Pages/{Module}/Index.vue`
- **Layout**: `AppLayout.vue` مع slot #header
- **Forms**: `useForm()` من Inertia
- **State**: `ref()` + `computed()` + `watch()`

## 7. رقم الهاتف
- يُخزن بالكامل مع رمز الدولة: `+962712345678`
- الأردن: `+962` + 9 أرقام تبدأ بـ 7
- السعودية: `+966` + 9 أرقام تبدأ بـ 5

## 8. التصميم (Theme)
- **Dark/Light Mode**: يُحفظ في `localStorage` بمفتاح `nusuk-theme`
- **CSS Overrides**: ملف `app.css` يحتوي dark mode عالمي
- **RTL**: التطبيق بالكامل RTL (اتجاه من اليمين لليسار)
- **ألوان Gold**: التدرج الأساسي للعلامة التجارية
