# Database Schema - NUSUK System
## PostgreSQL 16 | All monetary fields use DECIMAL to prevent floating-point loss

---

## 1. المستخدمين والصلاحيات (RBAC)

### roles
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PK | |
| name | VARCHAR(50) | NOT NULL, UNIQUE | 'مدير عام', 'موظف مبيعات', 'محاسب' |
| slug | VARCHAR(50) | NOT NULL, UNIQUE | 'admin', 'sales', 'accountant' |
| description | TEXT | | |
| created_at | TIMESTAMP | DEFAULT NOW() | |
| updated_at | TIMESTAMP | DEFAULT NOW() | |

### permissions
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PK | |
| name | VARCHAR(100) | NOT NULL | |
| slug | VARCHAR(100) | NOT NULL, UNIQUE | مثال: 'agents.view', 'transfers.approve' |
| module | VARCHAR(50) | NOT NULL | 'agents', 'clients', 'transfers', 'invoices' |
| description | TEXT | | |

### role_permissions (pivot)
| Column | Type | Constraints |
|--------|------|-------------|
| id | SERIAL | PK |
| role_id | INTEGER | FK→roles, NOT NULL |
| permission_id | INTEGER | FK→permissions, NOT NULL |
| | | UNIQUE(role_id, permission_id) |

### users
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PK | |
| name | VARCHAR(100) | NOT NULL | |
| email | VARCHAR(150) | NOT NULL, UNIQUE | |
| password | VARCHAR(255) | NOT NULL | Hashed |
| role_id | INTEGER | FK→roles, NOT NULL | |
| phone | VARCHAR(20) | | |
| avatar | VARCHAR(500) | | |
| locale | VARCHAR(5) | DEFAULT 'ar' | 'ar' or 'en' |
| theme | VARCHAR(10) | DEFAULT 'light' | 'light' or 'dark' |
| is_active | BOOLEAN | DEFAULT TRUE | |
| fcm_token | TEXT | | Firebase push token |
| last_login_at | TIMESTAMP | | |
| created_at | TIMESTAMP | DEFAULT NOW() | |
| updated_at | TIMESTAMP | DEFAULT NOW() | |

---

## 2. البيانات التأسيسية (Master Data)

### agents (الوكلاء - السعودية)
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PK | |
| name | VARCHAR(150) | NOT NULL | |
| code | VARCHAR(20) | NOT NULL, UNIQUE | كود مختصر |
| phone | VARCHAR(20) | | |
| email | VARCHAR(150) | | |
| address | TEXT | | |
| city | VARCHAR(100) | | |
| country | VARCHAR(5) | DEFAULT 'SA' | |
| balance_sar | DECIMAL(15,2) | DEFAULT 0.00 | رصيد دائن بالريال (cached) |
| notes | TEXT | | |
| is_active | BOOLEAN | DEFAULT TRUE | |
| created_by | INTEGER | FK→users | |
| created_at | TIMESTAMP | DEFAULT NOW() | |
| updated_at | TIMESTAMP | DEFAULT NOW() | |

**قواعد الرصيد (balance_sar):**
- يزيد عند اعتماد حوالة (transfer)
- ينقص عند اعتماد مخالفة (violation)
- ينقص عند اعتماد فاتورة (invoice) - فقط بنود الخدمات

### clients (العملاء/المكاتب - الأردن)
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PK | |
| name | VARCHAR(150) | NOT NULL | |
| code | VARCHAR(20) | NOT NULL, UNIQUE | |
| phone | VARCHAR(20) | | |
| email | VARCHAR(150) | | |
| address | TEXT | | |
| city | VARCHAR(100) | | |
| country | VARCHAR(5) | DEFAULT 'JO' | |
| balance_jod | DECIMAL(15,3) | DEFAULT 0.000 | ذمة مدينة بالدينار (cached) |
| credit_limit_jod | DECIMAL(15,3) | DEFAULT 0.000 | سقف ائتماني |
| notes | TEXT | | |
| is_active | BOOLEAN | DEFAULT TRUE | |
| created_by | INTEGER | FK→users | |
| created_at | TIMESTAMP | DEFAULT NOW() | |
| updated_at | TIMESTAMP | DEFAULT NOW() | |

**قواعد الرصيد (balance_jod):**
- يزيد عند اعتماد فاتورة (يصبح مدين)
- ينقص عند اعتماد سند قبض (دفعة واردة)

### services (الخدمات)
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PK | |
| name | VARCHAR(150) | NOT NULL | |
| code | VARCHAR(20) | NOT NULL, UNIQUE | |
| default_price_sar | DECIMAL(15,2) | DEFAULT 0.00 | سعر التكلفة الافتراضي |
| default_price_jod | DECIMAL(15,3) | DEFAULT 0.000 | سعر البيع الافتراضي |
| description | TEXT | | |
| is_active | BOOLEAN | DEFAULT TRUE | |
| created_at | TIMESTAMP | DEFAULT NOW() | |
| updated_at | TIMESTAMP | DEFAULT NOW() | |

### violation_types (أنواع المخالفات)
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PK | |
| name | VARCHAR(150) | NOT NULL | |
| code | VARCHAR(20) | NOT NULL, UNIQUE | |
| default_cost_sar | DECIMAL(15,2) | DEFAULT 0.00 | |
| description | TEXT | | |
| is_active | BOOLEAN | DEFAULT TRUE | |
| created_at | TIMESTAMP | DEFAULT NOW() | |
| updated_at | TIMESTAMP | DEFAULT NOW() | |

---

## 3. أسعار الصرف

### exchange_rates
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PK | |
| rate_date | DATE | NOT NULL, UNIQUE | سعر واحد لكل يوم |
| sar_to_jod | DECIMAL(10,6) | NOT NULL | 1 SAR = X JOD |
| jod_to_sar | DECIMAL(10,6) | NOT NULL | 1 JOD = X SAR (معكوس) |
| set_by | INTEGER | FK→users | |
| notes | TEXT | | |
| created_at | TIMESTAMP | DEFAULT NOW() | |
| updated_at | TIMESTAMP | DEFAULT NOW() | |

---

## 4. إعدادات النظام

### settings
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PK | |
| key | VARCHAR(100) | NOT NULL, UNIQUE | |
| value | TEXT | | |
| group_name | VARCHAR(50) | DEFAULT 'general' | 'general','company','printing','notifications' |
| type | VARCHAR(20) | DEFAULT 'string' | 'string','number','boolean','json','image' |
| description | TEXT | | |
| updated_at | TIMESTAMP | DEFAULT NOW() | |

**الإعدادات المبدئية:**
- `company_name_ar`, `company_name_en`, `company_logo`
- `company_address`, `company_phone`, `tax_number`
- `invoice_prefix` (INV), `receipt_prefix` (RCP), `transfer_prefix` (TRF)

---

## 5. العمليات المالية

### transfers (الحوالات - شحن أرصدة الوكلاء)
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PK | |
| transfer_number | VARCHAR(30) | NOT NULL, UNIQUE | TRF-YYYYMMDD-XXXX |
| agent_id | INTEGER | FK→agents, NOT NULL | |
| amount_sar | DECIMAL(15,2) | NOT NULL | المبلغ المحول بالريال |
| cost_jod | DECIMAL(15,3) | NOT NULL | التكلفة بالدينار |
| exchange_rate | DECIMAL(10,6) | NOT NULL | سعر الصرف المجمد |
| payment_method | VARCHAR(30) | NOT NULL | 'bank_transfer','cash','check' |
| reference_number | VARCHAR(100) | | رقم مرجعي |
| transfer_date | DATE | NOT NULL | |
| notes | TEXT | | |
| status | VARCHAR(20) | DEFAULT 'pending' | 'pending','approved','rejected' |
| rejection_reason | TEXT | | |
| created_by | INTEGER | FK→users, NOT NULL | الموظف المُدخل |
| approved_by | INTEGER | FK→users | المدير المعتمد |
| approved_at | TIMESTAMP | | |
| created_at | TIMESTAMP | DEFAULT NOW() | |
| updated_at | TIMESTAMP | DEFAULT NOW() | |

**عند الاعتماد:** `agents.balance_sar += amount_sar`

### receipts (سندات القبض - تحصيل من العملاء)
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PK | |
| receipt_number | VARCHAR(30) | NOT NULL, UNIQUE | RCP-YYYYMMDD-XXXX |
| client_id | INTEGER | FK→clients, NOT NULL | |
| amount_jod | DECIMAL(15,3) | NOT NULL | |
| payment_method | VARCHAR(30) | NOT NULL | 'cash','check','bank_transfer' |
| reference_number | VARCHAR(100) | | |
| receipt_date | DATE | NOT NULL | |
| bank_name | VARCHAR(100) | | للشيكات |
| check_date | DATE | | للشيكات المؤجلة |
| notes | TEXT | | |
| status | VARCHAR(20) | DEFAULT 'pending' | |
| rejection_reason | TEXT | | |
| created_by | INTEGER | FK→users, NOT NULL | |
| approved_by | INTEGER | FK→users | |
| approved_at | TIMESTAMP | | |
| created_at | TIMESTAMP | DEFAULT NOW() | |
| updated_at | TIMESTAMP | DEFAULT NOW() | |

**عند الاعتماد:** `clients.balance_jod -= amount_jod`

### expense_categories (تصنيفات المصاريف)
| Column | Type | Constraints |
|--------|------|-------------|
| id | SERIAL | PK |
| name | VARCHAR(100) | NOT NULL |
| code | VARCHAR(20) | NOT NULL, UNIQUE |
| description | TEXT | |
| is_active | BOOLEAN | DEFAULT TRUE |

### expenses (المصاريف التشغيلية)
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PK | |
| expense_number | VARCHAR(30) | NOT NULL, UNIQUE | EXP-YYYYMMDD-XXXX |
| category_id | INTEGER | FK→expense_categories, NOT NULL | |
| description | TEXT | NOT NULL | |
| amount | DECIMAL(15,3) | NOT NULL | |
| currency | VARCHAR(3) | DEFAULT 'JOD' | 'JOD' or 'SAR' |
| expense_date | DATE | NOT NULL | |
| payment_method | VARCHAR(30) | | |
| reference_number | VARCHAR(100) | | |
| notes | TEXT | | |
| status | VARCHAR(20) | DEFAULT 'pending' | |
| rejection_reason | TEXT | | |
| created_by | INTEGER | FK→users, NOT NULL | |
| approved_by | INTEGER | FK→users | |
| approved_at | TIMESTAMP | | |
| created_at | TIMESTAMP | DEFAULT NOW() | |
| updated_at | TIMESTAMP | DEFAULT NOW() | |

---

## 6. المخالفات

### violations
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PK | |
| violation_number | VARCHAR(30) | NOT NULL, UNIQUE | VIO-YYYYMMDD-XXXX |
| agent_id | INTEGER | FK→agents, NOT NULL | |
| client_id | INTEGER | FK→clients, NOT NULL | |
| violation_type_id | INTEGER | FK→violation_types, NOT NULL | |
| passport_number | VARCHAR(30) | NOT NULL | |
| passport_name | VARCHAR(150) | | |
| cost_sar | DECIMAL(15,2) | NOT NULL | التكلفة بالريال |
| violation_date | DATE | NOT NULL | |
| description | TEXT | | |
| notes | TEXT | | |
| billing_status | VARCHAR(20) | DEFAULT 'unbilled' | 'unbilled' / 'billed' |
| invoice_id | INTEGER | FK→invoices, NULLABLE | يُعبأ عند الفوترة |
| status | VARCHAR(20) | DEFAULT 'pending' | 'pending','approved','rejected' |
| rejection_reason | TEXT | | |
| created_by | INTEGER | FK→users, NOT NULL | |
| approved_by | INTEGER | FK→users | |
| approved_at | TIMESTAMP | | |
| created_at | TIMESTAMP | DEFAULT NOW() | |
| updated_at | TIMESTAMP | DEFAULT NOW() | |

**عند الاعتماد:** `agents.balance_sar -= cost_sar` + تصبح `billing_status = 'unbilled'`
**عند الفوترة:** `billing_status = 'billed'` + يُسجل `invoice_id`

---

## 7. الفواتير

### invoices
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PK | |
| invoice_number | VARCHAR(30) | NOT NULL, UNIQUE | INV-YYYYMMDD-XXXX |
| agent_id | INTEGER | FK→agents, NOT NULL | |
| client_id | INTEGER | FK→clients, NOT NULL | |
| exchange_rate_snapshot | DECIMAL(10,6) | NOT NULL | سعر الصرف المجمد |
| subtotal_sar | DECIMAL(15,2) | DEFAULT 0.00 | |
| discount_sar | DECIMAL(15,2) | DEFAULT 0.00 | |
| total_sar | DECIMAL(15,2) | DEFAULT 0.00 | |
| total_jod | DECIMAL(15,3) | DEFAULT 0.000 | |
| services_cost_sar | DECIMAL(15,2) | DEFAULT 0.00 | تكلفة الخدمات فقط |
| violations_cost_sar | DECIMAL(15,2) | DEFAULT 0.00 | تكلفة المخالفات (مخصومة سابقاً) |
| profit_sar | DECIMAL(15,2) | DEFAULT 0.00 | |
| profit_jod | DECIMAL(15,3) | DEFAULT 0.000 | |
| invoice_date | DATE | NOT NULL | |
| due_date | DATE | | |
| notes | TEXT | | |
| status | VARCHAR(20) | DEFAULT 'draft' | 'draft','pending','approved','rejected' |
| rejection_reason | TEXT | | |
| created_by | INTEGER | FK→users, NOT NULL | |
| approved_by | INTEGER | FK→users | |
| approved_at | TIMESTAMP | | |
| created_at | TIMESTAMP | DEFAULT NOW() | |
| updated_at | TIMESTAMP | DEFAULT NOW() | |

**عند الاعتماد:**
1. `agents.balance_sar -= services_cost_sar` (الخدمات فقط، المخالفات خُصمت سابقاً)
2. `clients.balance_jod += total_jod` (إجمالي الفاتورة بالدينار)
3. المخالفات المضمّنة → `billing_status = 'billed'`

### invoice_items (بنود الفاتورة)
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PK | |
| invoice_id | INTEGER | FK→invoices, ON DELETE CASCADE | |
| item_type | VARCHAR(20) | NOT NULL | 'service' or 'violation' |
| service_id | INTEGER | FK→services, NULLABLE | إذا كان خدمة |
| violation_id | INTEGER | FK→violations, NULLABLE | إذا كان مخالفة |
| description | VARCHAR(255) | NOT NULL | |
| quantity | INTEGER | DEFAULT 1 | |
| unit_price_sar | DECIMAL(15,2) | NOT NULL | سعر التكلفة |
| sell_price_jod | DECIMAL(15,3) | NOT NULL | سعر البيع |
| total_cost_sar | DECIMAL(15,2) | NOT NULL | |
| total_sell_jod | DECIMAL(15,3) | NOT NULL | |
| sort_order | INTEGER | DEFAULT 0 | |
| created_at | TIMESTAMP | DEFAULT NOW() | |

---

## 8. سجل الحركات المالية (Ledger)

### ledger_entries
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | SERIAL | PK | |
| entry_date | TIMESTAMP | DEFAULT NOW() | |
| entity_type | VARCHAR(20) | NOT NULL | 'agent' or 'client' |
| entity_id | INTEGER | NOT NULL | |
| transaction_type | VARCHAR(30) | NOT NULL | 'transfer','violation','invoice','receipt','opening_balance','adjustment' |
| transaction_id | INTEGER | | ID العملية المرتبطة |
| debit | DECIMAL(15,3) | DEFAULT 0.000 | |
| credit | DECIMAL(15,3) | DEFAULT 0.000 | |
| balance_after | DECIMAL(15,3) | NOT NULL | الرصيد بعد القيد |
| currency | VARCHAR(3) | NOT NULL | 'SAR' or 'JOD' |
| description | TEXT | | |
| created_by | INTEGER | FK→users | |
| created_at | TIMESTAMP | DEFAULT NOW() | |

**Indexes:** `(entity_type, entity_id)`, `(entry_date)`, `(transaction_type, transaction_id)`

---

## 9. الإشعارات

### notifications
| Column | Type | Constraints |
|--------|------|-------------|
| id | SERIAL | PK |
| user_id | INTEGER | FK→users, ON DELETE CASCADE |
| title | VARCHAR(255) | NOT NULL |
| body | TEXT | |
| type | VARCHAR(50) | NOT NULL — 'approval_request','approval_result','system' |
| icon | VARCHAR(50) | |
| action_url | VARCHAR(500) | Deep link |
| data | JSONB | |
| is_read | BOOLEAN | DEFAULT FALSE |
| read_at | TIMESTAMP | |
| created_at | TIMESTAMP | DEFAULT NOW() |

**Index:** `(user_id, is_read)`

---

## 10. سجل النشاطات (Audit Log)

### activity_logs
| Column | Type | Constraints |
|--------|------|-------------|
| id | SERIAL | PK |
| user_id | INTEGER | FK→users |
| action | VARCHAR(50) | NOT NULL — 'created','updated','approved','rejected','deleted' |
| model_type | VARCHAR(50) | NOT NULL |
| model_id | INTEGER | NOT NULL |
| old_values | JSONB | |
| new_values | JSONB | |
| ip_address | VARCHAR(45) | |
| user_agent | TEXT | |
| created_at | TIMESTAMP | DEFAULT NOW() |

---

## 11. المرفقات (Polymorphic)

### attachments
| Column | Type | Constraints |
|--------|------|-------------|
| id | SERIAL | PK |
| attachable_type | VARCHAR(50) | NOT NULL — 'transfer','receipt','expense','invoice' |
| attachable_id | INTEGER | NOT NULL |
| file_name | VARCHAR(255) | NOT NULL |
| file_path | VARCHAR(500) | NOT NULL |
| file_type | VARCHAR(50) | |
| file_size | INTEGER | bytes |
| uploaded_by | INTEGER | FK→users |
| created_at | TIMESTAMP | DEFAULT NOW() |

**Index:** `(attachable_type, attachable_id)`

---

## مخطط العلاقات (ERD Summary)

```
roles 1──M users
users 1──M [transfers, receipts, expenses, violations, invoices] (created_by)
users 1──M [transfers, receipts, expenses, violations, invoices] (approved_by)

agents 1──M transfers
agents 1──M violations
agents 1──M invoices

clients 1──M receipts
clients 1──M violations
clients 1──M invoices

violation_types 1──M violations
services 1──M invoice_items

invoices 1──M invoice_items
invoices 1──M violations (invoice_id)

expense_categories 1──M expenses

-- Polymorphic
attachments → transfers | receipts | expenses | invoices
ledger_entries → agents | clients (via entity_type + entity_id)
activity_logs → any model (via model_type + model_id)
notifications → users
```

---

## ملاحظات هامة

1. **DECIMAL وليس FLOAT**: جميع الحقول المالية تستخدم DECIMAL لمنع ضياع الكسور
2. **SAR = 2 decimals** (هللة)، **JOD = 3 decimals** (فلس)
3. **balance_sar / balance_jod**: أرصدة مخبأة (cached) تُحدّث داخل DB Transaction
4. **سجل الحركات (ledger_entries)**: يوفر تتبع كامل وقابل للتدقيق لكل حركة مالية
5. **كل العمليات المالية** تملك: `status`, `created_by`, `approved_by`, `approved_at`
