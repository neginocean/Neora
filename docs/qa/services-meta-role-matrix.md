# وضعیت فعلی — ۲۰۲۶-۱۰-۲۴

- قرارداد قیمت با تأیید کاربر: **تومان، عدد صحیح بدون اعشار**. ورودی فارسی/عربی/لاتین به رشتهٔ عددی ASCII تبدیل می‌شود؛ صفر معتبر است. نقطه، ویرگول، جداکنندهٔ هزارگان، منفی و حروف پذیرفته نمی‌شوند. در فرم، قیمت نامعتبر مقدار قبلی را حفظ می‌کند و پیام خطا دارد؛ در REST با HTTP 400 رد می‌شود.
- نتیجهٔ نهایی PHPUnit: **۴۹ تست و ۲۰۷ assertion موفق**. `php -l` روی دو فایل PHP محصول/تست تغییرکرده موفق بود.
- WordPress واقعی و پایگاه دادهٔ Local: **۷۹ بررسی موفق** شامل پیش‌شرط‌ها، نقش‌ها، REST، hookهای ذخیره، حذف با null، nonce، متن، قیمت، Portfolio، lifecycle و بازگشت تراکنش. Editor و Administrator مجاز؛ Subscriber و مهمان غیرمجاز. Author با capabilityهای نقش در حافظه آزمایش شد، نه حساب Author واقعی.
- HTTP عمومی واقعی: **۷ بررسی موفق**؛ collection، تک‌نوشته، `_embed` و `_fields=meta` بدون دو فیلد خصوصی؛ درخواست مهمان با `context=edit` برابر 401؛ آرشیو و permalink برابر 200.
- داده‌های آزمایشی داخل تراکنش بودند و ROLLBACK تأیید شد؛ نبودن نوشته‌های موقت و ثابت‌ماندن options مربوط به rewrite و active_plugins نیز بررسی شد. هیچ حساب، نقش، رمز یا دادهٔ موجود کاربر تغییر داده نشد. ممکن است شمارهٔ auto-increment بر اثر تخصیص شناسهٔ آزمایشی جلو رفته باشد.
- ورود، کلیک و ارسال فرم از مرورگر، مشاهدهٔ بصری RTL/LTR و نمایش اعلان در UI واقعی انجام نشده‌اند؛ ابزار کنترل مرورگر در این نوبت در دسترس نبود. تست‌های نقش از `rest_do_request` و save hookهای واقعی اجرا شدند و جای تست session/cookie مرورگر را نمی‌گیرند.
- گزارش و فرمان‌های بازتولید: [core-live-qa-2026-10-24.md](core-live-qa-2026-10-24.md).

---

# سابقهٔ بازبینی — ۲۰۲۶-۱۰-۲۳

این بخش سابقهٔ مرحلهٔ قبل است؛ وضعیت جاری در ابتدای فایل ثبت شده است.

- دامنهٔ تأییدشده فقط Core است؛ Theme هنوز ساخته نشده است.
- طبق تصمیم کاربر، `_neora_service_code` و `_neora_service_price` فقط در REST با `context=edit` قابل دریافت هستند. مجوز خواندن در این context و نوشتن باید برای همان نوشته برقرار باشد.
- درخواست عمومی `GET /wp-json/wp/v2/service/{id}` و collection با context پیش‌فرض، هیچ‌یک از این دو کلید را نباید برگرداند؛ این انتظار برای کاربر واردشده در `context=view` هم برقرار است.
- برای دریافت مقادیر توسط Editor/Administrator از `GET /wp-json/wp/v2/service/{id}?context=edit` استفاده کنید. کاربر واردشدهٔ فاقد مجوز باید رد شود؛ درخواست بدون ورود نیز نباید دادهٔ خصوصی برگرداند.
- `auth_callback` کنترل مجوز نوشتن metadata است؛ به‌تنهایی تضمین محرمانگی پاسخ عمومی نیست. schema هر دو فیلد به context ویرایش محدود شده است.
- حذف ردیف با مقدار خالی، قرارداد فرم مدیریت است. در REST، رشتهٔ خالی می‌تواند به‌صورت مقدار ذخیره شود؛ برای حذف مقدار، `null` را جداگانه آزمایش کنید. معیار «هیچ ردیف خالی پس از هر نوع ذخیره» در بخش قدیمی، برای REST معتبر نیست.
- آزمون خودکار فعلی: ۴۲ تست، ۱۵۱ assertion، موفق. شامل تابع واقعی فیلتر context از WordPress نصب‌شده و تست‌های مبتنی بر Brain Monkey است؛ شامل اجرای HTTP واقعی، نقش‌های زنده یا پایگاه داده نیست.
- اجرای دستی مجدد: ذخیره و بازخوانی هر دو فیلد با Editor و Administrator؛ رد Subscriber و Author روی نوشتهٔ دیگران؛ GET عمومی تک‌نوشته/collection و `_embed` بدون نشت؛ GET مجاز با `context=edit`؛ فرم با nonce آرایه‌ای؛ متن شامل apostrophe و backslash؛ فعال‌سازی و غیرفعال‌سازی و بررسی permalinkها.
- نتیجهٔ آزمون دستی نسخهٔ فعلی: **اجرا نشده**. تا انجام آن، Gate نسخهٔ اول باز است.

گزارش تفصیلی: [core-release-review-2026-10-23.md](core-release-review-2026-10-23.md).

---

# Services Meta — Role Matrix QA Checklist

**Module:** Services (`_neora_service_code`, `_neora_service_price`)  
**Auth mechanism:** `MetaHelper::make_edit_post_auth_cb()` — object-aware `edit_post` check  
**Last updated:** 2026-10-10

---

## Prerequisites

- WordPress debug mode active (`WP_DEBUG=true`, `WP_DEBUG_LOG=true`)
- At least one published `service` post exists (note its ID as `{post_id}`)
- Three test accounts created and confirmed:
  - `qa-subscriber` → role: Subscriber
  - `qa-editor` → role: Editor
  - `qa-admin` → role: Administrator
- REST client available (curl, Postman, or browser REST plugin)
- Application password generated for each test account (Users → Edit → Application Passwords)

---

## 1. Subscriber — Must Fail

### 1a. wp-admin edit screen

1. Log in as `qa-subscriber`.
2. Navigate to `/wp-admin/post.php?post={post_id}&action=edit`.
3. **Expected:** WordPress redirects to dashboard with "You are not allowed to edit this post." No meta box visible.
4. If the page somehow loads, attempt to submit the form with values in Service Code and Service Price.
5. **Expected:** 403 / redirect — no meta rows written. Verify via WP-CLI or phpMyAdmin:
```sql
SELECT * FROM wp_postmeta
WHERE post_id = {post_id}
  AND meta_key IN ('_neora_service_code', '_neora_service_price');

Row count must be unchanged.

### 1b. REST update attempt

bash
curl -i -X POST \
  "https://your-site.test/wp-json/wp/v2/service/{post_id}" \
  -u "qa-subscriber:APPLICATION_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{"meta":{"_neora_service_code":"SUB-01","_neora_service_price":"999"}}'

**Expected HTTP status:** `403 Forbidden`

**Expected body fragment:**

json
{"code":"rest_cannot_edit","message":"Sorry, you are not allowed to edit this post."}

**Verify:** meta rows in DB unchanged (same query as above).

---

## 2. Editor — Must Pass on Permitted Post

### 2a. wp-admin edit screen

1. Log in as `qa-editor`.
2. Navigate to `/wp-admin/post.php?post={post_id}&action=edit`.
3. **Expected:** Post edit screen loads. Meta box ("Service Details" or equivalent) visible under the content area with `context=normal`.
4. Enter:
   - Service Code: `EDIT-01`
   - Service Price: `250000`
5. Click **Update**.
6. **Expected:** Post saves without error. Reload the page.
7. **Expected:** Both fields retain entered values.
8. Verify in DB:

sql
SELECT meta_key, meta_value FROM wp_postmeta
WHERE post_id = {post_id}
  AND meta_key IN ('_neora_service_code', '_neora_service_price');

Expected rows:
- `_neora_service_code = EDIT-01`
- `_neora_service_price = 250000`

### 2b. Editor — empty value clears row

1. Still logged in as `qa-editor`, edit the same post.
2. Clear both fields (leave blank), click **Update**.
3. **Expected:** Both meta rows deleted from `wp_postmeta` (not set to empty string).
4. Verify:

sql
SELECT COUNT(*) FROM wp_postmeta
WHERE post_id = {post_id}
  AND meta_key IN ('_neora_service_code', '_neora_service_price');

Expected count: `0`.

### 2c. REST update attempt

bash
curl -i -X POST \
  "https://your-site.test/wp-json/wp/v2/service/{post_id}" \
  -u "qa-editor:APPLICATION_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{"meta":{"_neora_service_code":"REST-EDIT","_neora_service_price":"500000"}}'

**Expected HTTP status:** `200 OK`

**Expected:** Response body includes:

json
"meta": {
  "_neora_service_code": "REST-EDIT",
  "_neora_service_price": "500000"
}

---

## 3. Administrator — Must Pass

### 3a. wp-admin edit screen

1. Log in as `qa-admin`.
2. Navigate to `/wp-admin/post.php?post={post_id}&action=edit`.
3. Enter:
   - Service Code: `ADM-99`
   - Service Price: `1500000`
4. Click **Update**.
5. **Expected:** Values persist on reload. DB rows present with correct values.

### 3b. REST update attempt

bash
curl -i -X POST \
  "https://your-site.test/wp-json/wp/v2/service/{post_id}" \
  -u "qa-admin:APPLICATION_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{"meta":{"_neora_service_code":"ADM-REST","_neora_service_price":"750000"}}'

**Expected HTTP status:** `200 OK`

**Expected:** Meta values updated and reflected in subsequent `GET` response.

### 3c. REST collection/schema context (`object_id = 0` path)

bash
curl -i -X GET \
  "https://your-site.test/wp-json/wp/v2/service"

**Expected:** No protected meta exposure in this collection/schema context (`object_id=0` path should not grant access).

---
---

## 4. Actual Test Execution Log

- **Date:** 2026-10-21
- **Environment:** Local WordPress / Browser REST API request
- **Tested User:** `qa-editor`
- **Target Post:** `service` post with ID `8`

### REST Read and Title Update

- **Endpoint:** `GET /wp-json/wp/v2/service/8`
- **Read Result:** `HTTP 200 OK`
- **Initial Title:** `custome service test1`

- **Endpoint:** `POST /wp-json/wp/v2/service/8`
- **Operation:** Temporarily update the post title to `QA REST authorized probe`
- **Update Result:** `HTTP 200 OK`
- **Update Verification:** `PASS`

- **Restore Operation:** Restore the original title
- **Restore Result:** `HTTP 200 OK`
- **Final Title:** `custome service test1`
- **Restore Verification:** `PASS`

### Status

**PASS** — `qa-editor` successfully read and updated the permitted `service` post title through the authenticated WordPress REST API. The original title was restored successfully.

### Scope Note

This execution verifies REST read/update permission for the post title only. It does not yet verify REST write/read behavior for:

- `_neora_service_code`
- `_neora_service_price`

## 4. Security Guard Checks

### 4a. Nonce tamper (admin screen)

1. Log in as `qa-admin`, open service edit screen.
2. Use browser DevTools to modify the nonce field value to `invalid_nonce`.
3. Submit the form.
4. **Expected:** Save rejected. `wp_verify_nonce` fails. No meta written or deleted. Check logs — no PHP errors.

### 4b. Autosave

1. Log in as `qa-editor`, open a service post.
2. Wait for WordPress autosave to fire (approximately 60 seconds of inactivity after a change).
3. **Expected:** Meta values unchanged — autosave guard in `save_meta` returns early. Verify DB rows are unchanged.

---

## 5. Role Matrix Summary

| Check | Subscriber | Editor | Administrator |
|---|---|---|---|
| wp-admin: edit screen access | ✗ Blocked (no access) | ✓ Allowed | ✓ Allowed |
| wp-admin: meta save | ✗ Blocked | ✓ Saved | ✓ Saved |
| REST `POST /service/{id}` meta | ✗ `403` | ✓ `200` | ✓ `200` |
| REST collection/schema context (`GET /service`, object_id=0 path) | ✗ No protected meta exposure | ✗ No protected meta exposure | ✗ No protected meta exposure |
| Empty value → row deleted | n/a | ✓ | ✓ |
| Nonce tamper → rejected | n/a | ✓ | ✓ |
| Autosave → meta unchanged | n/a | ✓ | ✓ |

---

## 6. Pass / Fail Criteria

- All checks in sections 1–4 must pass before merge.
- Any `WP_DEBUG`/PHP notice, warning, fatal, or `InvalidArgumentException` is a blocker.
- No empty-string rows (`meta_value = ''`) may exist for `_neora_service_code` or `_neora_service_price` in `wp_postmeta` after any save operation.


## 7. Actual Test Execution Log

- نتیجهٔ تست خودکار: PASS — 35 tests, 110 assertions.
- نتیجهٔ تست دستی: PASS — اجرای موفق تمام سناریوهای بخش‌های ۱ تا ۴،
  طبق تأیید تست‌کننده (نگین).
- وضعیت QA ماژول Services: تکمیل‌شده.
- بررسی نهایی تغییرات پیش از Merge: در انتظار انجام.
