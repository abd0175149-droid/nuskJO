# Routes & API - NUSUK System
## Inertia.js Routes (web.php)

---

## Authentication
| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | /login | AuthController@showLogin | صفحة الدخول |
| POST | /login | AuthController@login | تسجيل الدخول |
| POST | /logout | AuthController@logout | تسجيل الخروج |

## Dashboard
| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | / | DashboardController@index | لوحة القيادة المتقدمة |

## Agents (الوكلاء)
| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | /agents | AgentController@index | قائمة الوكلاء |
| GET | /agents/{id} | AgentController@show | كشف حساب الوكيل |
| POST | /agents | AgentController@store | حفظ وكيل جديد |
| PUT | /agents/{id} | AgentController@update | تحديث |
| DELETE | /agents/{id} | AgentController@destroy | حذف |

## Clients (العملاء)
| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | /clients | ClientController@index | قائمة العملاء |
| GET | /clients/{id} | ClientController@show | كشف حساب العميل |
| POST | /clients | ClientController@store | حفظ |
| PUT | /clients/{id} | ClientController@update | تحديث |
| DELETE | /clients/{id} | ClientController@destroy | حذف |

## Transfers (الحوالات)
| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | /transfers | TransferController@index | القائمة |
| POST | /transfers | TransferController@store | حفظ (status=pending) |
| DELETE | /transfers/{id} | TransferController@destroy | حذف المعلقة |
| POST | /transfers/{id}/approve | TransferController@approve | اعتماد |
| POST | /transfers/{id}/reject | TransferController@reject | رفض |

## Receipts (سندات القبض)
| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | /receipts | ReceiptController@index | القائمة |
| POST | /receipts | ReceiptController@store | حفظ |
| DELETE | /receipts/{id} | ReceiptController@destroy | حذف |
| POST | /receipts/{id}/approve | ReceiptController@approve | اعتماد |
| POST | /receipts/{id}/reject | ReceiptController@reject | رفض |

## Violations (المخالفات)
| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | /violations | ViolationController@index | القائمة |
| POST | /violations | ViolationController@store | حفظ |
| DELETE | /violations/{id} | ViolationController@destroy | حذف |
| POST | /violations/{id}/approve | ViolationController@approve | اعتماد + خصم رصيد |
| POST | /violations/{id}/reject | ViolationController@reject | رفض |

## Invoices (الفواتير)
| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | /invoices | InvoiceController@index | القائمة + POS |
| POST | /invoices | InvoiceController@store | حفظ فاتورة كاملة |
| DELETE | /invoices/{id} | InvoiceController@destroy | حذف |
| POST | /invoices/{id}/approve | InvoiceController@approve | اعتماد + تحديث أرصدة |
| POST | /invoices/{id}/reject | InvoiceController@reject | رفض |

## Expenses (المصاريف)
| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | /expenses | ExpenseController@index | القائمة |
| POST | /expenses | ExpenseController@store | حفظ |
| DELETE | /expenses/{id} | ExpenseController@destroy | حذف |
| POST | /expenses/{id}/approve | ExpenseController@approve | اعتماد |
| POST | /expenses/{id}/reject | ExpenseController@reject | رفض |

## Services (الخدمات)
| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | /services | ServiceController@index | القائمة |
| POST | /services | ServiceController@store | إضافة |
| PUT | /services/{id} | ServiceController@update | تعديل |
| DELETE | /services/{id} | ServiceController@destroy | حذف |

## Violation Types (أنواع المخالفات)
| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | /violation-types | ViolationTypeController@index | القائمة |
| POST | /violation-types | ViolationTypeController@store | إضافة |
| PUT | /violation-types/{id} | ViolationTypeController@update | تعديل |
| DELETE | /violation-types/{id} | ViolationTypeController@destroy | حذف |

## Expense Categories (تصنيفات المصاريف)
| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | /expense-categories | ExpenseCategoryController@index | القائمة |
| POST | /expense-categories | ExpenseCategoryController@store | إضافة |
| PUT | /expense-categories/{id} | ExpenseCategoryController@update | تعديل |
| DELETE | /expense-categories/{id} | ExpenseCategoryController@destroy | حذف |

## Settings (الإعدادات)
| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | /settings | SettingController@index | شاشة الإعدادات |
| PUT | /settings | SettingController@update | تحديث الإعدادات |
| POST | /settings/exchange-rate | SettingController@storeExchangeRate | سعر صرف |

## Users & Roles (الموظفين والصلاحيات)
| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | /users | UserController@index | قائمة الموظفين |
| POST | /users | UserController@store | إضافة موظف |
| PUT | /users/{id} | UserController@update | تعديل موظف |
| DELETE | /users/{id} | UserController@destroy | حذف موظف |
| GET | /roles | RoleController@index | قائمة الصلاحيات |
| POST | /roles | RoleController@store | إنشاء صلاحية جديدة |
| PUT | /roles/{id} | RoleController@update | تعديل صلاحية |
| DELETE | /roles/{id} | RoleController@destroy | حذف صلاحية |


## Reports (التقارير)
| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | /reports/agents-balances | ReportController@agentsBalances | أرصدة الوكلاء |
| GET | /reports/clients-balances | ReportController@clientsBalances | ذمم العملاء |
| GET | /reports/profit-loss | ReportController@profitLoss | الأرباح والخسائر |
| GET | /reports/daily-summary | ReportController@dailySummary | ملخص يومي |

## Print Pages (صفحات الطباعة)
| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | /invoices/{id}/print | InvoiceController@print | طباعة فاتورة مبيعات |
| GET | /agents/{id}/print-statement | AgentController@printStatement | طباعة كشف حساب وكيل |
| GET | /clients/{id}/print-statement | ClientController@printStatement | طباعة كشف حساب عميل |

## API Endpoints
| Method | Route | Description |
|--------|-------|-------------|
| GET | /api/clients/{id}/violations/unbilled | مخالفات العميل غير المفوترة |
