NEORA_PROJECT_STATE.md
سند مرجع وضعیت فنی، تصمیم‌های معماری، فازبندی و نتایج تست‌های پروژه Neora.
این فایل برای تداوم دقیق توسعه در چت‌های جدید نگهداری می‌شود.

۱. نمای کلی و چشم‌انداز پروژه
نام محصول: Neora (Neora Core + Neora Theme)
پلتفرم پایه: WordPress (حداقل WP 6.3، حداقل PHP 8.1)
نسخه فعلی Core: 0.1.0 (نسخه اسکیما/مهاجرت داخلی: 1.0.0)
وضعیت Neora Theme: هنوز ایجاد نشده است (فازهای ۵ و ۶).
هدف محصول: اکوسیستم ماژولار، امن، سریع (هدف زیر ۱ ثانیه روی صفحات بهینه‌شده) و قابل عرضه تجاری برای بازار ایران و بین‌الملل (RTL/LTR، فارسی/انگلیسی، چندارزی، پشتیبانی E.164 و چند-Provider پیامکی، و سیستم حساب کاربری عمومی مستقل از WooCommerce).

۲. وضعیت فعلی فازها
فاز	عنوان	وضعیت	یادداشت دقیق
فاز ۰	تثبیت، رفع باگ‌های امنیتی و آماده‌سازی مهندسی	تکمیل و تأیید شده	OK (106 tests, 384 assertions) + php -l بدون خطا
فاز ۱ — ۱.۱	گراف وابستگی ماژول‌ها (DependentModuleInterface)	تکمیل و تأیید شده	OK (113 tests, 409 assertions) + مرتب‌سازی توپولوژیک و تشخیص چرخه
فاز ۱ — ۱.۲	زیرساخت Migration و چرخه عمر نسخه (MigrationRunner)	تکمیل و تأیید شده	OK (119 tests, 442 assertions) + قفل همزمانی و M001NormalizeModuleKeys
فاز ۱ — ۱.۳	سرویس چندارزی و تنظیمات منطقه‌ای (CurrencyService)	تکمیل و تأیید شده	OK (129 tests, 511 assertions) + کاتالوگ ۶ ارز، قالب‌بندی رشته‌ای بدون سرریز و همگام‌سازی اختیاری WC
فاز ۱ — ۱.۴	White-Label v2 و تکمیل .pot / .po / .l10n.php	تکمیل و تأیید شده	OK (138 tests, 1118 assertions) + BrandingService، سرصفحه یکپارچه و همگام‌سازی کامل ترجمه‌ها
فاز ۲	معماری چند-Provider پیامک، استاندارد E.164 و صف غیرهمگام	تکمیل و تأیید شده	OK (152 tests, 1320 assertions) + SmsProviderInterface، درایورهای Melipayamak/Twilio/Webhook، استاندارد E.164 و SmsQueue
فاز ۳	سیستم حساب کاربری عمومی (Universal Account System) — Core & REST	مرحله بعدی	در نوبت اجرا (مستقل از WooCommerce + پل اختیاری WC)
فاز ۴	تکمیل Services، Portfolio، Polylang و قرارداد API رسمی Theme-to-Core	در صف	—
فاز ۵	Neora Theme — Design System و صفحات عمومی	در صف	—
فاز ۶	Neora Theme — رابط کاربری حساب کاربری، OTP و فروشگاه	در صف	—
فاز ۷	بهینه‌سازی زیر ۱ ثانیه، تست E2E، امنیت نهایی و تجاری‌سازی	در صف	—

۳. تصمیم‌های کلیدی معماری (تثبیت‌شده)
حفظ Core با Zero Runtime Vendor Dependencies: استفاده از Autoloader سبک داخلی (neora-core.php) و نگه‌داشتن composer.json فقط برای require-dev.
سیاست امنیتی احراز هویت OTP (Role-Aware Auth Policy):
ورود بدون رمز با OTP به‌صورت پیش‌فرض فقط برای نقش‌های کاربری/مشتری (subscriber, customer) مجاز است و برای نقش‌های مدیریتی (manage_options, edit_others_posts, manage_woocommerce) مسدود می‌شود (قابل تنظیم با فیلترهای neora_auth_allowed_roles و neora_auth_can_user_login_with_otp).
تطبیق خودکار کاربر بر اساس billing_phone تاییدنشده به‌صورت پیش‌فرض غیرفعال است (allow_unverified_billing_login = false) و در صورت فعال‌سازی، فقط با یکتا بودن شماره و عدم امتیاز مدیریتی مجاز است.
محدودسازی دامنه OTP (Purpose Scoping): کدهای OTP با random_int() (از طریق تزریق اختیاری code_generator در OtpManager) تولید شده و به purpose (مثل login) و شماره موبایل E.164 در هش و transient مقید می‌شوند.
یکسان‌سازی کلید ماژول‌ها: تمام ماژول‌ها از الگوی neora/* پیروی می‌کنند (neora/auth، neora/portfolio، neora/services، neora/polylang، neora/woocommerce، neora/admin-menu، neora/settings-page) همراه با نگاشت سازگار در ModuleStateStore و مهاجرت دائمی M001NormalizeModuleKeys.
قرارداد چرخه عمر بازنویسی URL: معرفی RewriteAwareInterface به‌جای وابستگی سخت Bootstrap::activate() به instanceof کلاس‌های ماژول.
گراف وابستگی ماژول‌ها (DependentModuleInterface) و تفکیک قابلیت‌ها:
ماژول‌ها با پیاده‌سازی DependentModuleInterface::get_dependencies() پیش‌نیازهای سخت داخلی را اعلام می‌کنند؛ ModuleManager::resolve_boot_plan() با DFS سه‌رنگ مرتب‌سازی توپولوژیک، کشف چرخه، بررسی زنجیره‌ای و ثبت خطای قابل نمایش در داشبورد ادمین را انجام می‌دهد و ModuleStateStore::save_states() تغییر وضعیت rewrite ماژول‌های وابسته را نیز تشخیص می‌دهد.
WooCommerceBridgeModule به‌صورت آگاهانه به neora/auth وابسته سخت نشده است تا پیامک تراکنشی سفارش و اعتبارسنجی موبایل تسویه‌حساب مستقل کار کنند؛ هم‌زمان متدهای همگام‌سازی هویت OTP آن در زمان اجرا به فعال‌بودن neora/auth مقید شده‌اند (is_auth_module_active()).
زیرساخت Migration مستقل از نسخه ظاهری افزونه (MigrationRunner):
پیگیری اسکیمای داخلی با MigrationRunner::CURRENT_SCHEMA_VERSION (1.0.0) در neora_core_db_version (autoloaded برای هزینه صفر در درخواست‌های عادی) و ثبت سابقه اجرا در neora_core_migrations همراه با قفل همزمانی neora_core_migration_lock.
قابل توسعه با فیلتر neora_core_migrations و متصل به Bootstrap::activate() و Bootstrap::init().
سرویس چندارزی و تنظیمات منطقه‌ای (CurrencyService):
کاتالوگ استاندارد ۶ ارز (IRT, IRR, USD, EUR, AED, TRY) قابل توسعه با فیلتر neora_core_currencies و ذخیره تنظیمات منطقه‌ای در neora_core_regional_settings (شامل default_currency، default_country به‌عنوان پیش‌نیاز استاندارد E.164، سبک ارقام auto/persian/latin، جداکننده هزارگان و اعشار، جایگاه نماد ارز و sync_woocommerce_currency).
نرمال‌سازی و قالب‌بندی تمام‌رشته‌ای بدون سرریز int/float (normalize_amount() و format()) همراه با ثبت سرویس neora.currency در Container، تب regional در SettingsPage، توابع عمومی در Functions.php و اتصال سازگار به ServicesModule، PolylangModule و WooCommerceBridgeModule با حفظ کامل قرارداد قیمت تومانی قبلی.
سرویس برندینگ White-Label v2 (BrandingService) و همگام‌سازی ترجمه‌ها:
متمرکزسازی گزینه‌های برندینگ در neora_core_general_settings (با حفظ سازگاری کامل عقب‌رو و پاک‌سازی در uninstall.php) شامل brand_name، menu_label، menu_icon (Dashicons قابل فیلتر با neora_core_branding_menu_icons)، logo_url (محدود به http/https)، brand_color (همراه با محاسبه متغیرهای CSS و کنتراست WCAG)، dashboard_subtitle، settings_subtitle، admin_footer_text، show_version_badge و show_system_info.
ثبت سرویس neora.branding در Container، فیلتر neora_core_branding_settings، پارشیال مشترک views/admin/partials/header.php، انتخابگر رسانه وردپرس (wp.media) و انتخابگر رنگ همگام در JS، توابع عمومی در Functions.php (neora_branding_service()، neora_get_branding_settings()، neora_get_brand_logo_url()، neora_get_brand_color()) و همگام‌سازی کامل ۱-به-۱ فایل‌های languages/neora-core.pot، languages/neora-core-fa_IR.po و languages/neora-core-fa_IR.l10n.php با تست تطابق خودکار.
معماری چند-Provider پیامک، استاندارد E.164 و صف غیرهمگام (فاز ۲):
انتزاع SmsProviderInterface و مسیریاب مرکزی SmsManager (ثبت‌شده در Container با کلید neora.sms_manager و حفظ neora.sms برای سازگاری عقب‌رو) با درایورهای MelipayamakProvider (ایران، مبتنی بر MelipayamakClient)، TwilioProvider (بین‌المللی با HTTP Basic Auth بدون SDK خارجی) و WebhookProvider (HTTP JSON عمومی)، قابل توسعه با فیلتر neora_core_sms_providers و مسیریابی خودکار بین default_provider و international_provider در neora_sms_settings.
رمزنگاری توکن‌های بین‌المللی (twilio_auth_token و webhook_secret) در کنار password از طریق SecretBox همراه با پشتیبانی از ثابت‌های NEORA_TWILIO_AUTH_TOKEN و NEORA_WEBHOOK_SECRET.
استانداردسازی بین‌المللی شماره موبایل در PhoneNumber مبتنی بر default_country در CurrencyService و فیلتر neora_core_phone_country_rules؛ ارتقای OtpManager و AuthModule به E.164 همراه با پارامتر اختیاری country، endpoint جدید GET /neora/v1/auth/countries، مهاجرت امن حساب‌های قدیمی 09xx در _neora_mobile به E.164، و به‌روزرسانی WooCommerceBridgeModule (شامل enforce_international_phone و async_order_sms) و Functions.php (neora_sms_manager()، neora_sms_queue()، neora_normalize_phone_e164()، neora_send_sms()، neora_queue_sms() و فرمت E.164 در neora_get_user_mobile()).
صف غیرهمگام SmsQueue (کلید neora.sms_queue در Container) مبتنی بر گزینه غیر-autoload neora_sms_queue، قفل همزمانی neora_sms_queue_lock، زمان‌بندی wp_schedule_single_event و Cron پشتیبان ۵ دقیقه‌ای neora_sms_process_queue با بازتلاش نمایی (۶۰/۱۲۰/۲۴۰ ثانیه) برای خطاهای موقت و شکست فوری برای خطاهای دائمی، همراه با کارت مدیریت صف در تب SMS و پاک‌سازی کامل در Bootstrap::deactivate() و uninstall.php.

۴. تغییرات واقعی اعمال‌شده و تأییدشده
فاز ۰ (تکمیل‌شده)
فایل‌های جدید: NEORA_PROJECT_STATE.md، src/Core/RewriteAwareInterface.php، assets/admin/neora-admin.js، phpunit.xml.dist، phpcs.xml.dist.
فایل‌های ویرایش‌شده: tests/bootstrap.php، src/Core/ModuleStateStore.php، src/Core/Bootstrap.php، src/Modules/Services/ServicesModule.php، src/Modules/Portfolio/PortfolioModule.php، src/Modules/Multilingual/PolylangModule.php، src/Support/SecretBox.php، src/Admin/SettingsPage.php، src/Services/Sms/OtpManager.php، src/Modules/Auth/AuthModule.php، src/Modules/WooCommerce/WooCommerceBridgeModule.php، languages/neora-core-fa_IR.l10n.php و تست‌های مرتبط.
فاز ۱ — مرحله ۱.۱ (تکمیل‌شده)
فایل‌های جدید: src/Core/DependentModuleInterface.php، tests/Core/ModuleDependencyGraphTest.php.
فایل‌های ویرایش‌شده: src/Core/ModuleManager.php، src/Core/ModuleStateStore.php، src/Core/Bootstrap.php، src/Admin/AdminMenu.php، src/Modules/Multilingual/PolylangModule.php، src/Modules/WooCommerce/WooCommerceBridgeModule.php، views/admin/dashboard.php، languages/neora-core-fa_IR.l10n.php، tests/Core/ModuleManagerTest.php، tests/Core/ModuleStateStoreTest.php.
فاز ۱ — مرحله ۱.۲ (تکمیل‌شده)
فایل‌های جدید: src/Core/Migration/MigrationInterface.php، src/Core/Migration/MigrationRunner.php، src/Core/Migration/Migrations/M001NormalizeModuleKeys.php، tests/Core/MigrationRunnerTest.php.
فایل‌های ویرایش‌شده: src/Core/Bootstrap.php، uninstall.php.
فاز ۱ — مرحله ۱.۳ (تکمیل‌شده)
فایل‌های جدید: src/Services/Currency/CurrencyService.php، tests/Integration/CurrencyServiceTest.php.
فایل‌های ویرایش‌شده: src/Core/Bootstrap.php، src/Admin/SettingsPage.php، views/admin/settings.php، src/Support/Functions.php، src/Modules/Services/ServicesModule.php، src/Modules/Multilingual/PolylangModule.php، src/Modules/WooCommerce/WooCommerceBridgeModule.php، uninstall.php، languages/neora-core-fa_IR.l10n.php، tests/Integration/FunctionsTest.php، tests/Integration/ServicePriceTest.php، tests/Integration/ServicesModuleIntegrationTest.php، tests/Integration/ReleaseRegressionTest.php، tests/Integration/SettingsPageIntegrationTest.php، tests/Integration/PolylangModuleTest.php.
فاز ۱ — مرحله ۱.۴ (تکمیل‌شده)
فایل‌های جدید: src/Services/Branding/BrandingService.php، views/admin/partials/header.php، tests/Integration/BrandingServiceTest.php.
فایل‌های ویرایشی: src/Core/Bootstrap.php، src/Admin/AdminMenu.php، src/Admin/SettingsPage.php، views/admin/dashboard.php، views/admin/settings.php، assets/admin/neora-admin.css، assets/admin/neora-admin.js، src/Support/Functions.php، languages/neora-core.pot، languages/neora-core-fa_IR.po، languages/neora-core-fa_IR.l10n.php، tests/Integration/SettingsPageIntegrationTest.php، tests/Integration/FunctionsTest.php.
فاز ۲ (تکمیل‌شده)
فایل‌های جدید: src/Services/Sms/SmsProviderInterface.php، src/Services/Sms/PhoneNumber.php، src/Services/Sms/Providers/MelipayamakProvider.php، src/Services/Sms/Providers/TwilioProvider.php، src/Services/Sms/Providers/WebhookProvider.php، src/Services/Sms/SmsManager.php، src/Services/Sms/SmsQueue.php، tests/Integration/PhoneNumberTest.php، tests/Integration/SmsManagerTest.php، tests/Integration/SmsQueueTest.php.
فایل‌های ویرایشی: src/Core/Bootstrap.php، src/Services/Sms/OtpManager.php، src/Modules/Auth/AuthModule.php، src/Support/Functions.php، src/Modules/WooCommerce/WooCommerceBridgeModule.php، src/Admin/SettingsPage.php، views/admin/settings.php، assets/admin/neora-admin.js، uninstall.php، languages/neora-core.pot، languages/neora-core-fa_IR.po، languages/neora-core-fa_IR.l10n.php، tests/Integration/OtpManagerTest.php، tests/Integration/AuthModuleTest.php، tests/Integration/FunctionsTest.php، tests/Integration/SettingsPageIntegrationTest.php، tests/Integration/WooCommerceBridgeModuleTest.php.

۵. نتایج تست‌های تأییدشده
وضعیت فعلی روی دیسک (پایان فاز ۲):
php vendor/bin/phpunit --do-not-cache-result ← OK (152 tests, 1320 assertions)

۶. دستورالعمل ادامه (فاز ۳ به بعد)
گام بعدی: فاز ۳ — سیستم حساب کاربری عمومی (Universal Account System) — Core & REST (مستقل از WooCommerce + پل اختیاری WC).
گام پس از آن: فاز ۴ — تکمیل Services، Portfolio، Polylang و قرارداد API رسمی Theme-to-Core.