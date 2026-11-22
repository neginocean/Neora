# بازبینی Core — ۲۰۲۶-۱۰-۲۳

## دامنه و وضعیت واقعی

کاربر تأیید کرد فعلاً فقط Core وجود دارد. مسیر واقعی افزونه `app/public/wp-content/plugins/neora-core` است. ZIPها استخراج نشدند. `views/admin/dashboard.php` نمای مدیریتی متعلق به افزونه است، نه Theme یا پروژهٔ جداگانه. `src/Support/Functions.php` فعلاً خالی است. هیچ قابلیت فرانت‌اند یا WooCommerce از این بررسی نتیجه نمی‌شود.

ورودی `neora-core.php` در `plugins_loaded`، `Bootstrap` را راه‌اندازی می‌کند. `Application` مالک `Container` و `ModuleManager` است. همهٔ ماژول‌ها ابتدا register و سپس boot می‌شوند. `HookRegistry` به hookهای WordPress واگذار می‌کند. ماژول‌ها: AdminMenu، Services و Portfolio. CPTها: `service` و `neora_portfolio`؛ taxonomyها: `service_category` و `portfolio_category`.

در مسیر پروژه و افزونه مخزن Git یافت نشد و دستور git در PATH نبود؛ بنابراین git status/diff قابل ارائه نیست. فایل‌های پیش از اصلاح، از جمله فایل‌های نامتعارف موجود، در `.neora-review/2026-10-23/before` در ریشهٔ سایت حفظ شده‌اند. هیچ فایل موجود حذف نشد. داده‌های سایت، ZIPها و vendor ویرایش نشدند.

## ماتریس نیاز، شواهد و پذیرش

شمارهٔ خطوط زیر مربوط به نسخهٔ نهایی است؛ نسخهٔ اولیه برای مقایسه در backup موجود است.

| نیاز | وضعیت اولیه | شواهد | تغییر | آزمون پذیرش / نتیجه |
|---|---|---|---|---|
| ذخیرهٔ سالم متن | خراب | `src/Core/MetaHelper.php:151` و `ServicesModule.php:205` | unslash در مرز فرم؛ sanitizer مستقل از transport؛ slash پیش از ذخیره | متن شامل apostrophe و backslash حفظ می‌شود؛ PASS |
| رد ورودی نامعتبر بدون خراب‌کردن داده | ناقص | `MetaHelper.php:124,154`؛ `ServicesModule.php:317`؛ `PortfolioModule.php:258` | رد nonce غیررشته‌ای و ورودی مرکب برای فیلد scalar | هیچ update/delete برای ورودی آرایه‌ای؛ PASS |
| سال معتبر چهاررقمی | خراب | `PortfolioModule.php:298` | اعتبارسنجی پیش از تبدیل عددی | `2024abc`، `2024.9`، float و array رد می‌شوند؛ PASS |
| REST خصوصی کد و قیمت | خراب/ناقص | `ServicesModule.php:204,216` | ثبت هر دو فیلد فقط با schema context=edit؛ حفظ edit_post | حذف از view/embed با تابع واقعی WordPress و بررسی auth؛ PASS؛ HTTP زنده باقی است |
| پاک‌شدن routeها در غیرفعال‌سازی | ناقص | `Bootstrap.php:84` | unregister دو CPT و دو taxonomy پیش از flush | ترتیب فراخوانی‌ها؛ PASS؛ permalink واقعی باقی است |
| ترجمه‌پذیری Services | ناقص | `ServicesModule.php:199,211,281` | رشته‌های literal قابل استخراج و escaping پس از ترجمه | بازبینی کد و lint؛ بستهٔ ترجمه/نمایش فارسی آزمایش نشده |
| تفکیک مسئولیت‌ها | موجود در Core | `src/Admin/AdminMenu.php` و `views/admin/dashboard.php` | حفظ ساختار فعلی | بازبینی کد؛ Theme خارج از دامنه |
| عدم نیاز اجباری به CDN | در Core موجود | جست‌وجوی src، views، assets و ورودی افزونه | تغییر لازم نبود | هیچ درخواست اجرایی خارجی/enqueue خارجی یافت نشد؛ URLهای header صرفاً metadata هستند |
| سازگاری نسخه‌ها | بخشی تأییدشده | header افزونه، composer.json، PHP اجراشده | وابستگی تغییر نکرد | PHP 8.2.29 و PHPUnit 9.6.38؛ حداقل PHP 8.1 و WP 6.3 عملاً آزمایش نشده |

## یافته‌ها بر اساس شدت

- **زیاد — حریم خصوصی REST:** Service Code قبلاً `show_in_rest=true` داشت؛ قیمت اصلاً در REST ثبت نبود. auth_callback به‌تنهایی خواندن عمومی را محدود نمی‌کند. پس از تصمیم صریح کاربر، هر دو schema به edit محدود شدند. دریافت مقادیر نیازمند `?context=edit` است. مجوز همان نوشته در controller نصب‌شده بررسی می‌شود؛ تست کامل نقش‌ها همچنان لازم است.
- **متوسط — تغییر ناخواستهٔ متن:** زنجیرهٔ unslash در Services و API ذخیره باعث حذف backslash می‌شد. تست قبل از اصلاح مقدار `C:\design\file` را `C:designfile` دریافت کرد. مسئولیت unslash به مرز فرم منتقل شد و مقدار برای API ذخیره دوباره slash می‌شود. این تغییر داده‌های قبلیِ آسیب‌دیده را بازسازی نمی‌کند.
- **متوسط — ورودی آرایه‌ای:** nonce آرایه‌ای در Services هشدار Array to string conversion و sanitizer فیلد scalar خطای TypeError ایجاد می‌کرد. اکنون چنین ورودی‌هایی پیش از تبدیل رد می‌شوند و مقدار قبلی scalar تغییر نمی‌کند.
- **متوسط — سال نامعتبر:** تبدیل مستقیم به int مقدار `2024abc` را به 2024 تبدیل می‌کرد؛ اکنون فقط چهار رقم معتبر با بازهٔ قبلی پذیرفته می‌شود. سال ذخیره‌شدهٔ قبلی مهاجرت داده نشده است.
- **متوسط — lifecycle:** flush در حالی انجام می‌شد که CPT و taxonomy هنوز ثبت بودند؛ unregister پیش از flush اضافه شد. فعال‌سازی/غیرفعال‌سازی واقعی اجرا نشده است.
- **کم — استخراج ترجمه:** label و placeholder متغیر، استخراج خودکار رشته‌های Services را دشوار می‌کردند؛ literalهای ترجمه اضافه شدند. پوشهٔ languages و بستهٔ فارسی در نسخهٔ بررسی‌شده وجود ندارد.

## آزمون‌ها و دستورهای واقعی

مسیر اجرای همهٔ دستورهای زیر:

```powershell
Set-Location 'C:\Users\Negin\Local Sites\neora-core\app\public\wp-content\plugins\neora-core'
php vendor/bin/phpunit --do-not-cache-result
php vendor/bin/phpunit --do-not-cache-result --filter ReleaseRegressionTest
composer validate --no-check-publish --no-interaction
```

- قبل از اصلاح: ۳۵ تست، ۱۱۰ assertion، موفق.
- پنج تست رگرسیون اولیه روی کد قبلی: ۳ error و ۲ failure؛ مشکلات ورودی، slash، سال و غیرفعال‌سازی بازتولید شدند.
- پس از اصلاح کامل: ۴۲ تست، ۱۵۱ assertion، موفق، بدون skipped.
- `php -l` روی هر ۸ فایل PHP تغییرکرده/جدید اجرا شد؛ همه بدون خطای syntax.
- Composer معتبر است؛ warningهای موجود: license در composer.json تعیین نشده و نسخهٔ root package از Git قابل تشخیص نیست. composer.json/lock تغییر نکردند.
- suite با نام Integration عمدتاً از Brain Monkey استفاده می‌کند؛ تست اضافه‌شدهٔ REST تابع واقعی `rest_filter_response_by_context` از نصب کنار افزونه را اجرا می‌کند. اگر افزونه جدا از WordPress اجرا شود، فقط این تست context با پیام روشن skip می‌شود.
- تلاش برای GET عمومی در آدرس آزمایشی `http://neora-core.local/wp-json/wp/v2/service?per_page=1` با `Invoke-WebRequest -UseBasicParsing -TimeoutSec 10` انجام شد؛ اتصال برقرار نشد (`Unable to connect to the remote server`). آدرس و روشن‌بودن سایت Local باید برای QA زنده تأیید شوند. هیچ نتیجهٔ HTTP موفق از این تلاش به دست نیامد.
- هیچ درخواست ارسال فرم، تغییر نقش، نوشتن دادهٔ آزمایشی یا تراکنش در سایت زنده انجام نشد.

## محدودیت‌ها و Gate باقی‌مانده

1. اجرای HTTP و UI با Subscriber، Editor، Administrator و Authorِ فاقد مجوز روی نوشتهٔ دیگران، برای هر دو فیلد و هر دو مسیر فرم/REST باقی است. سند services-meta-role-matrix سناریوها را مشخص می‌کند.
2. قرارداد حذف مقدار خالی در فرم حفظ شد. REST استاندارد می‌تواند رشتهٔ خالی را ذخیره کند؛ ادعای قدیمی QA مبنی بر حذف ردیف در تمام مسیرها معتبر نیست. تست حذف REST با null باید جداگانه انجام شود.
3. رفتار موجود «نبودن post_key در فرم دارای nonce معتبر، باعث حذف مقدار آن فیلد می‌شود» تغییر نکرد؛ تست موجود این قرارداد را تصریح می‌کند. این قرارداد برای API partial update استفاده نمی‌شود.
4. اعتبارسنجی قیمت همچنان حذف حروف و حفظ رقم/نقطه/ویرگول است؛ واحد پول و قالب اعشاری/هزارگان مشخص نشده‌اند. رشته‌هایی مثل `1..2` هنوز معتبرسازی معنایی نمی‌شوند؛ تا تصمیم محصولی، قرارداد ذخیره تغییر نکرد.
5. RTL/LTR و دسترس‌پذیری فرم مدیریت نیازمند بررسی بصری است. Theme و Glassmorphism طبق تأیید کاربر خارج از این مرحله هستند. ترجمه‌پذیری با وجود ترجمهٔ آماده برابر نیست.
6. uninstall فعلی داده‌ای حذف نمی‌کند؛ نوشته‌ها و metadata حفظ می‌شوند. سیاست حذف داده تصمیم‌گیری یا اجرا نشده است.

**نتیجه:** اصلاحات Core از Gate خودکار عبور کرده‌اند؛ آمادگی کامل نسخهٔ اول تا QA زنده و تعیین قرارداد قیمت تأیید نمی‌شود.
