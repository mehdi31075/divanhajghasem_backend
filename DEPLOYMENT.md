<div dir="rtl" align="right">

## ویرایشگر جاری پنل و حساب اپ — ۲۰۲۶/۱۰/۰۹

ویرایشگر فعال پنل CKEditor 5 است و فایل‌های آن در `public/assets/ckeditor/` نگهداری می‌شوند. SVGهای داخلی toolbar حفظ می‌شوند؛ نسخهٔ پنل نباید markup آیکن‌ها را جایگزین کند. بستهٔ ویرایشگر قبلاً روی هاست نصب و ۳۲ آیکن SVG در Chrome با cache-bust دیده شدند. کد فعلی حساب عمومی اپ، ورود OTP، صفحهٔ کاربران پنل و شمار بازدید را نیز اضافه می‌کند؛ این تغییر تازه هنوز روی هاست نصب نشده است. پیش از استفاده، از دیتابیس backup بگیرید، سپس migration افزایشی `2026_10_09_000004_add_app_accounts_and_article_views.php` را با `php artisan migrate --force` اجرا و فایل‌های هم‌نسخهٔ بک‌اند را مستقر کنید. OTP فعلاً آزمایشی است و از API به اپ نمایش داده می‌شود؛ درگاه پیامکی متصل نیست. بستهٔ قدیمی ویرایشگر `/private/tmp/divan-ckeditor-visible-20261009.zip` فقط همان نسخهٔ ویرایشگر است و شامل قابلیت حساب/بازدید نیست.

## سازگاری آدرس داخل APK قدیمی — ۲۰۲۶/۱۰/۰۷

APK نسخهٔ 1.5 آدرس `//api.php` را با دو اسلش درخواست می‌کند. مسیر سازگار در `routes/divan.php` اضافه و روی هاست نصب شد. هنگام بازبینی Force SSL روشن بود و HTTP پاسخ ۳۰۱ می‌داد؛ کاربر بعداً خودش ریدایرکت را خاموش کرد. دریافت دسته‌ها، مطالب دسته، جزئیات و تصویر با آدرس‌های HTTP دقیق APK و پاسخ مستقیم ۲۰۰ تأیید شد؛ کاربر نیز درست‌شدن اپ را اعلام کرد. APK روی دستگاه کاربر بررسی شده، نه شبیه‌ساز ما. [گزارش بررسی APK و مسیر شکست](LEGACY_ANDROID_REVIEW.md).

## تاریخچه: به‌روزرسانی پیشین پنل روی هاست — ۲۰۲۶/۱۰/۰۷

CKEditor 5 با ابزارهای پنل CKEditor قبلی روی سایت اصلی نصب و در Chrome واقعی بررسی شد. بسته فقط `assets/ckeditor/`، دو فایل JS/CSS پنل و قالب Blade را به‌روزرسانی می‌کند؛ دیتابیس، `.env` و رمزها در آن نیستند. برای نصب دستی، `output/divan-editor-update-20261007.zip` را در پوشهٔ دامنه (هم‌سطح `divan` و `public_html`) استخراج کنید. مسیرها داخل ZIP مطابق همین چیدمان‌اند. این ZIP خروجی محلیِ این سیستم است و در Git نگهداری نمی‌شود؛ فایل‌های آمادهٔ ویرایشگر در `public/assets/ckeditor/` در مخزن ثبت شده‌اند. Node روی هاست لازم نیست.

پشتیبان فایل‌های قبلی پنل و ReaderController در سیستم توسعه، `/private/tmp/divan-panel-before-ckeditor-20261007/` نگهداری می‌شود. فرم آزمایشی فقط در مرورگر بود و روی دیتابیس زنده ذخیره نشد. API عمومی `/api.php` تغییر مسیر نکرده و بدون توکن قابل خواندن است؛ مقدار صفر در شناسه/limit اصلاح شد.

# استقرار Laravel بدون از کار افتادن خواندن اپ قدیمی

نسخهٔ کد قبلی در تاریخچهٔ Git (baseline `7678bac` و نسخهٔ پنل توکنی `85e3550`) موجود است. نصب لاراول جایگزین معماری PHP قبلی می‌شود؛ پوشه‌ها و فایل‌های قدیمی را با پروژهٔ جدید مخلوط نکنید.

## آماده‌سازی

1. هاست باید PHP 8.3 یا جدیدتر، Composer 2، PDO MySQL، mbstring، OpenSSL، fileinfo، XML، ctype، tokenizer و mod_rewrite یا تنظیمات معادل Nginx داشته باشد. GD برای تست تولید تصویر لازم است. `.user.ini` در `public/` محدودیت آپلود ویدیو را روی 52M و اندازهٔ کل درخواست را روی 60M می‌گذارد؛ اگر هاست این فایل را نادیده بگیرد، همین حدود را در DirectAdmin تنظیم کنید.
2. از **کل دیتابیس، فایل‌های کد و تمام `upload/` زنده** backup قابل بازیابی بگیرید. تصاویر همراه مخزن فقط snapshot فایل پیوست‌اند؛ ممکن است سرور تصاویر جدیدتری داشته باشد.
3. پروژه را ابتدا در یک مسیر خصوصی تازه خارج از document root آپلود/clone کنید. `.env` و `vendor` و `storage` نباید از وب قابل خواندن باشند. `.git` هم خصوصی بماند.
4. فایل‌های واقعی زندهٔ `upload/` را به `public/upload/` کپی کنید و مسیر `/upload/category/...` را بدون تغییر حفظ کنید. تصویر موجود روی سرور را با snapshot قدیمی مخزن بازنویسی نکنید.
5. از `.env.example` یک `.env` خصوصی بسازید. مشخصات اتصال دیتابیس فعلی را از تنظیمات قبلی هاست وارد کنید. `APP_URL=https://divanhajghasem.ir`، `APP_ENV=production`، `APP_DEBUG=false`، `DIVAN_REQUIRE_HTTPS=true` و `DB_CONNECTION=mysql`، `DB_HOST=localhost`، `DB_DATABASE=divanhaj_db`، `DB_USERNAME=divanhaj_db`، `DB_CHARSET=utf8mb3` و `DB_COLLATION=utf8mb3_general_ci` باشد. رمزها به Git اضافه نشوند.

<div dir="ltr" align="left">

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan key:generate --force
php artisan migrate --force
php artisan divan:check
php artisan optimize
```

</div>

`migrate` در دیتابیس موجود فقط جدول‌های مفقود و ثبت migrations را اضافه می‌کند؛ نام و دادهٔ جدول‌های اصلی تغییر نمی‌کنند. **migrate:fresh / migrate:refresh / db:wipe اجرا نکنید.** بک‌اند SQLite ندارد؛ dump خصوصی و `.env.testing` روی وب منتشر نشوند. فایل SQL ارسالی را دوباره روی دیتابیس موجود import نکنید. هیچ seed با رمز پیش‌فرض وجود ندارد.

دسترسی نوشتن برای کاربر PHP فقط به `storage/`، `bootstrap/cache/`، `public/upload/category/` و `public/upload/news-media/` بدهید؛ از chmod 777 عمومی استفاده نکنید. جدول‌های MyISAM موجود به صورت خودکار به InnoDB تبدیل نمی‌شوند. dump ارسالی از MariaDB 10.6.24 است؛ ستون بدنهٔ نوشته `LONGTEXT` و جدول‌های اصلی `utf8mb3` هستند. اجرای تست‌های پاک‌کننده فقط روی دیتابیس مستقل با پسوند `_test` مجاز است؛ کاربر تست به دیتابیس اصلی دسترسی نداشته باشد.

## انتقال وب

روش استاندارد تنظیم Document root به **`PROJECT/public`** است. تنظیم به ریشهٔ پروژه، `.env` و کد خصوصی را در معرض دسترسی می‌گذارد و صحیح نیست.

برای DirectAdmin با ریشهٔ ثابت `public_html`، پروژهٔ خصوصی را در پوشهٔ هم‌سطح **`divan`** قرار دهید و محتویات `public/` (همراه `.htaccess` و `.user.ini`) را به `public_html` منتقل کنید. `index.php` جدید این دو چیدمان را تشخیص می‌دهد و `public_path` را تنظیم می‌کند. وقتی پوشهٔ `divan/public` منتقل شده باشد، bootstrap برای دستورات Artisan هم از `public_html` هم‌سطح استفاده می‌کند؛ بنابراین مسیر ذخیرهٔ تصاویر و ویدیوها در وب و CLI یکسان است.

<div dir="ltr" align="left">

```text
/home/divanhaj/domains/divanhajghasem.ir/
├── divan/         # app, bootstrap, config, vendor, storage, .env, artisan, ...
└── public_html/   # index.php, .htaccess, assets, upload, ...
```

</div>

در ۲۰۲۶/۱۰/۰۷ این چیدمان روی هاست بررسی و اصلاح شد: مسیر اشتباه autoload/bootstrap خطای ۵۰۰ می‌داد؛ پس از اصلاح، صفحهٔ پنل باز شد. دو جدول مفقود احراز هویت (`divan_api_tokens` و `divan_api_login_attempts`) مطابق migration و با `CREATE TABLE IF NOT EXISTS` از phpMyAdmin اضافه شدند. در ۲۰۲۶/۱۰/۰۸ ستون‌های nullable تاریخ `created_at` و `updated_at` به `tbl_news` افزوده شدند؛ تاریخ‌های قدیمی نامعلوم ماندند. فایل migration در کد موجود است و با `hasColumn` می‌تواند بدون افزودن دوبارهٔ ستون‌ها ثبت شود. اجرای کامل Artisan migration و ثبت جدول migrations هنوز انجام نشده؛ اجرای بعدی `migrate --force` جدول‌های موجود را دوباره نمی‌سازد.

Apache: فایل `public/.htaccess` همراه پروژه و mod_rewrite فعال باشد. فایل فیزیکی قدیمی `api.php` و `mobile-api.php` در document root باقی نماند؛ وگرنه مسیرهای Laravel را دور می‌زنند. `/api.php` یک URL لاراول است و باید به public/index.php rewrite شود. هدر Authorization نیز در .htaccess حفظ شده است.

Nginx نمونه، با مسیر پروژهٔ خودتان:

<div dir="ltr" align="left">

```nginx
root /path/to/divan/public;
index index.php;
location / { try_files $uri $uri/ /index.php?$query_string; }
# api.php/mobile-api.php فایل فیزیکی نیستند؛ همچنان با URL قدیمی route می‌شوند.
location = /index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
# همهٔ URLهای PHP قدیمی از front controller عبور می‌کنند.
location ~ \.php$ { rewrite ^ /index.php last; }
location ~ /\. { deny all; }
```

</div>

خواندن `/api.php` با HTTP مجاز بماند تا کلاینت قدیمی نیازمند تغییر نشود. برای پنل از HTTPS معتبر استفاده کنید؛ اعتبار گواهی فعلی سایت منقضی گزارش شده و باید تمدید شود. پشت reverse proxy، HTTPS را در تنظیمات سرور به PHP منتقل کنید؛ هدرهای proxy ناشناس را بدون محدودیت trust نکنید.

### کتابخانهٔ رسانه و پشتیبانی در اپ

به‌روزرسانی مورخ ۲۰۲۶/۱۰/۰۸، migration افزایشی `2026_10_08_000002_add_media_library_and_support.php` را اضافه می‌کند. پیش از استفاده از کتابخانهٔ رسانه و بخش پشتیبانی، `php artisan migrate --force` را با PHP 8.3 اجرا کنید؛ migration دو جدول مستقل `divan_media_names` و `divan_support_messages` می‌سازد و جدول‌های قدیمی را تغییر نمی‌دهد. فایل‌های رسانه‌ای همچنان در `public/upload/news-media/` می‌مانند؛ نام نمایشی در دیتابیس نگهداری می‌شود و تغییر نام URL فایل را عوض نمی‌کند. حذف از کتابخانه، خود فایل آپلودشده را برای همیشه حذف می‌کند.

بعد از استقرار نسخهٔ جدید پنل و API، اپ می‌تواند پیام متنی پشتیبانی بفرستد. پاسخ API شامل کد پیگیری یک‌باره است؛ اپ همین کد را نگه می‌دارد تا کاربر پاسخ مدیر را در همان بخش ببیند یا با واردکردن کد در دستگاه دیگری بازیابی کند. متن درخواست فقط در سرور ذخیره می‌شود. ارسال عمومی محدود به پنج پیام در ساعت برای هر IP است. مدیریت کتابخانه و پاسخ‌ها فقط با Bearer token مدیر انجام می‌شود.

در DirectAdmin گزینهٔ **Force SSL with https redirect** خاموش بماند؛ فعال‌کردن ریدایرکت سراسری، خواندن HTTP اپ قدیمی را هم به HTTPS منتقل می‌کند. برای `/api.php` و `/upload/category/` ریدایرکت HTTPS در Apache/Nginx یا پراکسی اضافه نکنید. `APP_URL=https://...` و `DIVAN_REQUIRE_HTTPS=true` خودشان درخواست عمومی را ریدایرکت نمی‌کنند؛ شرط HTTPS همچنان برای ورود و مدیریت حفظ می‌شود.

بررسی زندهٔ ۲۰۲۶/۱۰/۰۷ با User-Agent اندروید و بدون دنبال‌کردن ریدایرکت: گزینهٔ Force SSL خاموش بود؛ `http://divanhajghasem.ir/api.php`، جزئیات `?nid=85`، `http://www.divanhajghasem.ir/api.php` و تصویر `/upload/category/9370-2024-03-11.png` همگی مستقیم ۲۰۰ و بدون هدر Location پاسخ دادند. درخواست مدیریت HTTP (`mobile-api.php?action=me`) پاسخ JSON با کد ۴۲۶ داد، نه ریدایرکت. بنابراین در این درخواست‌ها ریدایرکت سرور مشاهده نشد؛ خطای گزارش‌شدهٔ اپ قدیمی هنوز بدون متن خطا یا درخواست واقعی آن قابل بازتولید نیست. در این بررسی تنظیم هاست تغییر داده نشد.

کاربر کرش اپ قدیمی هنگام دریافت دسته‌ها را گزارش کرد. بررسی با `Apache-HttpClient/UNAVAILABLE (java 1.4)` و `Dalvik/1.6.0` روی Android 4.4.2 نیز ۲۰۰، بدون ریدایرکت، بدون فشرده‌سازی و با ۳۲ رکورد دارای فیلدهای رشته‌ای بود. پاسخ دسته‌ها با ۳۲ رکورد dump ارسالی، ترتیب `cid DESC` و JSON فشردهٔ API اصلی بایت‌به‌بایت برابر بود (۸۶۷۴ بایت). APK یا سورس/گزارش کرش اپ قدیمی هنوز در دسترس نیست؛ علت کرش تأیید یا رفع نشده است.

## بررسی پیش از جایگزینی نهایی

وضعیت بررسی ۲۰۲۶/۱۰/۰۷: پنل و ورود در Chrome موفق‌اند، اما بررسی مستقل HTTPS هنوز certificate has expired می‌دهد و API عمومی HTTP با درخواست پیش‌فرض Python پاسخ ۴۰۳ داد. بازبینی بعدی با User-Agent اندروید، دریافت عمومی ۳۲ دسته، ۳۲۱ نوشته و جزئیات بدون توکن را تأیید کرد. ناسازگاری شناسه/limit صفر نیز روی هاست اصلاح شد. اعتبار گواهی جداگانه باید تأیید شود.

- پاسخ‌های `/api.php`، `?cat_id=<id>`، `?nid=<id>` و `?latest_news=20` را قبل و بعد مقایسه کنید: کلید AndroidEbookApp، شناسه‌های رشته‌ای، ترتیب و [] در نبود رکورد یکسان باشد.
- چند URL تصویر قدیمی را بررسی کنید.
- خواندن اپ Android فعلی را بدون توکن آزمایش کنید.
- در HTTPS پنل با حساب موجود وارد شوید؛ روی یک مطلب آزمایشی ایجاد/ویرایش/حذف و آپلود تصویر را بررسی کنید. این آزمون‌های زنده در این محیط انجام نشده‌اند.
- پاسخ GET عمومی باید CORS `*` داشته باشد؛ Bearer در login/me/create/update/delete کار کند و هیچ Cookie مجوز مدیریت ندهد.
- بعد از تغییر config دوباره `php artisan optimize` اجرا شود.

Flutter برای مدیریت به طور پیش‌فرض `https://divanhajghasem.ir/mobile-api.php` را مصرف می‌کند؛ خواندن فعلی از مسیر api.php ادامه دارد. برای وب معمولی پس از نصب می‌توان `DIVAN_API_URL=https://divanhajghasem.ir/api.php` را استفاده کرد، چون api.php جدید نیز CORS استاندارد دارد. `DIVAN_TOKEN_API_URL` برای آدرس صریح محیط توسعه اختیاری است.

## بازگشت

اگر بررسی استقرار مشکل داشت، document root را به نسخهٔ قبلی برگردانید. migration جدول‌های اصلی را تغییر نمی‌دهد. اگر بعد از استقرار رمز مدیر تغییر کرده باشد، پنل PHP بسیار قدیمی bcrypt را نمی‌شناسد؛ از backup حساب‌ها برای بازگشت استفاده کنید یا پنل توکنی `85e3550` را هم با پشتیبانی bcrypt آماده کنید. مطالبی که پس از انتقال ایجاد شده‌اند را پیش از بازیابی backup جداگانه حفظ کنید. هیچ rollback یا حذف خودکار داده انجام نمی‌شود.

</div>
