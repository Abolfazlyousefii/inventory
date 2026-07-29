# قرارداد احراز هویت و همگام‌سازی CRM با ERP

این سند Contract موردنیاز سمت CRM است. CRM مرجع کاربران است و هیچ رمز خام، Hash رمز، Session ID، Cookie یا `APP_KEY` بین دو سامانه مبادله نمی‌شود.

## OAuth Client

- Client name: `Ariya Inventory ERP`
- Type: Confidential first-party web client
- Redirect URI (exact): `https://inv.ariyajanebi.ir/auth/crm/callback`
- Grant: OAuth2 Authorization Code
- Required scope: `erp.user.read`
- PKCE: پشتیبانی از `S256` الزامی/توصیه‌شده و در ERP به‌صورت پیش‌فرض فعال است.
- Client authentication: `client_id` و `client_secret` فقط در Token endpoint و از سمت سرور ERP.
- Access-token expiry: کوتاه، پیشنهاد ۵ تا ۱۵ دقیقه.
- Refresh token: برای Login فعلی لازم نیست و CRM نباید برای این Client صادر کند، مگر Contract آینده‌ای جدا تعریف شود.
- Authorization code: یک‌بارمصرف، عمر پیشنهادی حداکثر ۶۰ ثانیه.

### Authorization endpoint

`GET /oauth/authorize`

پارامترها: `client_id`, `redirect_uri`, `response_type=code`, `scope`, `state`, `code_challenge`, `code_challenge_method=S256`.

### Token endpoint

`POST /oauth/token` با Content-Type برابر `application/x-www-form-urlencoded`.

ورودی: `grant_type=authorization_code`, `client_id`, `client_secret`, `redirect_uri`, `code`, `code_verifier`.

خروجی موفق:

```json
{"access_token":"opaque-or-jwt","token_type":"Bearer","expires_in":600}
```

خطا با HTTP 400/401 و Schema استاندارد OAuth (`error`, `error_description`)؛ پاسخ نباید Secret را بازتاب دهد.

## کاربر جاری

- Method/path: `GET /api/integrations/erp/me`
- Authentication: `Authorization: Bearer <user-access-token>`
- Scope: `erp.user.read`

```json
{
  "data": {
    "id": "18",
    "name": "نام کاربر",
    "phone": "09xxxxxxxxx",
    "email": null,
    "is_active": true,
    "username": null,
    "personnel_code": "P-0018",
    "department": "فروش",
    "position": "فروشنده",
    "branch": "مرکزی",
    "manager_id": "4",
    "roles": ["Sales"],
    "updated_at": "2026-07-29T08:20:00Z"
  }
}
```

`id`, `name`, `is_active`, `roles` و `updated_at` باید معتبر باشند. هیچ `password`, `password_hash`, Token یا داده مالی در پاسخ قرار نگیرد. خطاها: 401 Token نامعتبر، 403 Scope ناکافی، 404 کاربر حذف‌شده، 422 داده ناسازگار، 5xx خطای موقت.

## تغییرات کاربران

- Method/path: `GET /api/integrations/erp/users/changes`
- Authentication: Bearer integration token مستقل از Token کاربران
- Query: `cursor` اختیاری و opaque، `limit` بین 10 تا 500
- ترتیب Eventها صعودی و پایدار؛ Cursor فقط پس از Batch موفق در ERP ذخیره می‌شود.

```json
{
  "data": [
    {
      "event_id": "1251",
      "type": "user.updated",
      "user": {
        "id": "18",
        "name": "نام کاربر",
        "phone": "09xxxxxxxxx",
        "is_active": true,
        "roles": ["Sales"],
        "manager_id": "4",
        "updated_at": "2026-07-29T08:20:00Z"
      }
    }
  ],
  "next_cursor": "1251",
  "has_more": false
}
```

Event types: `user.created`, `user.updated`, `user.deactivated`, `user.deleted`, `user.roles_changed`, `user.manager_changed`. Event حذف باید حداقل شناسه کاربر را داشته باشد؛ ERP حذف فیزیکی نمی‌کند و کاربر را غیرفعال می‌کند. پاسخ 400 برای Cursor نامعتبر، 401/403 برای Integration token، 429 همراه `Retry-After`، و 5xx برای خطای موقت است.

## Full reconciliation

- Method/path: `GET /api/integrations/erp/users`
- Authentication: همان Integration token
- Query: `page`, `cursor`, `limit`, و اختیاری `crm_user_id`
- Response باید `data` و یکی از `has_more/next_cursor` یا `meta.last_page` را داشته باشد.

## Role mapping

| CRM | ERP |
|---|---|
| Admin | admin |
| Sales | sales_user |
| SaleManager | sales_manager |
| StorageUser | warehouse_operator |
| StorageManager | warehouse_manager |
| Accountant | accountant |
| FinanceManager | finance_manager |
| Purchasing | purchasing_user |

Roleهای `super_admin`, `system_admin`, `auditor` در ERP محلی‌اند و CRM آن‌ها را حذف یا ایجاد نمی‌کند. Role ناشناخته خودکار ساخته نمی‌شود.

## رفتار تغییرات

- created: Upsert با `crm_user_id` و رمز تصادفی غیرقابل‌استفاده محلی.
- updated/phone changed: همان رکورد با `crm_user_id` Update می‌شود.
- deactivated/deleted: `is_active=false`؛ اسناد و User حذف نمی‌شوند.
- role changed: فقط Roleهای CRM-managed جایگزین و Roleهای Local-only حفظ می‌شوند.
- manager changed: `manager_crm_user_id` ذخیره و پس از Upsert کل Batch به `manager_id` متصل می‌شود.
- password changed: هیچ Event حاوی Password/Hash لازم نیست؛ ورود بعدی توسط CRM احراز می‌شود.
