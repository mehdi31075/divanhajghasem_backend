# استقرار Laravel بدون از کار افتادن خواندن اپ قدیمی

نسخهٔ کد قبلی در تاریخچهٔ Git (baseline `7678bac` و نسخهٔ پنل توکنی `85e3550`) موجود است. نصب لاراول جایگزین معماری PHP قبلی می‌شود؛ پوشه‌ها و فایل‌های قدیمی را با پروژهٔ جدید مخلوط نکنید.

## آماده‌سازی

1. هاست باید PHP 8.3 یا جدیدتر، Composer 2، PDO MySQL، mbstring، OpenSSL، fileinfo، XML، ctype، tokenizer و mod_rewrite یا تنظیمات معادل Nginx داشته باشد. GD برای تست تولید تصویر لازم است. `upload_max_filesize` حداقل 5M و `post_max_size` حداقل 6M تنظیم شود. MySQL/MariaDB فعلی قابل استفاده است؛ اتصال واقعی آن در این محیط آزموده نشده.
2. از **کل دیتابیس، فایل‌های کد و تمام `upload/` زنده** backup قابل بازیابی بگیرید. تصاویر همراه مخزن فقط snapshot فایل پیوست‌اند؛ ممکن است سرور تصاویر جدیدتری داشته باشد.
3. پروژه را ابتدا در یک مسیر خصوصی تازه خارج از document root آپلود/clone کنید. `.env` و `vendor` و `storage` نباید از وب قابل خواندن باشند. `.git` هم خصوصی بماند.
4. فایل‌های واقعی زندهٔ `upload/` را به `public/upload/` کپی کنید و مسیر `/upload/category/...` را بدون تغییر حفظ کنید. تصویر موجود روی سرور را با snapshot قدیمی مخزن بازنویسی نکنید.
5. از `.env.example` یک `.env` خصوصی بسازید. مشخصات اتصال دیتابیس فعلی را از تنظیمات قبلی هاست وارد کنید. `APP_URL=https://divanhajghasem.ir`، `APP_ENV=production`، `APP_DEBUG=false`، `DIVAN_REQUIRE_HTTPS=true` و charset مناسب جدول فعلی (`utf8`) باشد. رمزها به Git اضافه نشوند.

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan key:generate --force
php artisan migrate --force
php artisan divan:check
php artisan optimize
```

`migrate` در دیتابیس موجود فقط جدول‌های مفقود و ثبت migrations را اضافه می‌کند؛ نام و دادهٔ جدول‌های اصلی تغییر نمی‌کنند. **migrate:fresh / migrate:refresh / db:wipe اجرا نکنید.** فایل SQLite توسعه روی سرور آپلود نشود. هیچ seed با رمز پیش‌فرض وجود ندارد.

دسترسی نوشتن برای کاربر PHP فقط به `storage/`، `bootstrap/cache/` و `public/upload/category/` بدهید؛ از chmod 777 عمومی استفاده نکنید. جدول‌های MyISAM موجود به صورت خودکار به InnoDB تبدیل نمی‌شوند.

## انتقال وب

Document root باید دقیقاً **`PROJECT/public`** باشد. تنظیم به ریشهٔ پروژه، `.env` و کد خصوصی را در معرض دسترسی می‌گذارد و صحیح نیست. اگر cPanel اجازهٔ تغییر root نمی‌دهد، محتویات `public/` را در public_html قرار دهید و دو مسیر require در public/index.php را به پروژهٔ خصوصی اصلاح کنید؛ پوشهٔ public واقعی Laravel و مسیر public_path تصاویر باید به همین مسیر نگاشت شود. روش پیشنهادی تغییر document root است.

Apache: فایل `public/.htaccess` همراه پروژه و mod_rewrite فعال باشد. فایل فیزیکی قدیمی `api.php` و `mobile-api.php` در document root باقی نماند؛ وگرنه مسیرهای Laravel را دور می‌زنند. `/api.php` یک URL لاراول است و باید به public/index.php rewrite شود. هدر Authorization نیز در .htaccess حفظ شده است.

Nginx نمونه، با مسیر پروژهٔ خودتان:

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

خواندن `/api.php` با HTTP مجاز بماند تا کلاینت قدیمی نیازمند تغییر نشود. برای پنل از HTTPS معتبر استفاده کنید؛ اعتبار گواهی فعلی سایت منقضی گزارش شده و باید تمدید شود. پشت reverse proxy، HTTPS را در تنظیمات سرور به PHP منتقل کنید؛ هدرهای proxy ناشناس را بدون محدودیت trust نکنید.

## بررسی پیش از جایگزینی نهایی

- پاسخ‌های `/api.php`، `?cat_id=<id>`، `?nid=<id>` و `?latest_news=20` را قبل و بعد مقایسه کنید: کلید AndroidEbookApp، شناسه‌های رشته‌ای، ترتیب و [] در نبود رکورد یکسان باشد.
- چند URL تصویر قدیمی را بررسی کنید.
- خواندن اپ Android فعلی را بدون توکن آزمایش کنید.
- در HTTPS پنل با حساب موجود وارد شوید؛ روی یک مطلب آزمایشی ایجاد/ویرایش/حذف و آپلود تصویر را بررسی کنید. این آزمون‌های زنده در این محیط انجام نشده‌اند.
- پاسخ GET عمومی باید CORS `*` داشته باشد؛ Bearer در login/me/create/update/delete کار کند و هیچ Cookie مجوز مدیریت ندهد.
- بعد از تغییر config دوباره `php artisan optimize` اجرا شود.

Flutter برای مدیریت به طور پیش‌فرض `https://divanhajghasem.ir/mobile-api.php` را مصرف می‌کند؛ خواندن فعلی از مسیر api.php ادامه دارد. برای وب معمولی پس از نصب می‌توان `DIVAN_API_URL=https://divanhajghasem.ir/api.php` را استفاده کرد، چون api.php جدید نیز CORS استاندارد دارد. `DIVAN_TOKEN_API_URL` برای آدرس صریح محیط توسعه اختیاری است.

## بازگشت

اگر بررسی استقرار مشکل داشت، document root را به نسخهٔ قبلی برگردانید. migration جدول‌های اصلی را تغییر نمی‌دهد. اگر بعد از استقرار رمز مدیر تغییر کرده باشد، پنل PHP بسیار قدیمی bcrypt را نمی‌شناسد؛ از backup حساب‌ها برای بازگشت استفاده کنید یا پنل توکنی `85e3550` را هم با پشتیبانی bcrypt آماده کنید. مطالبی که پس از انتقال ایجاد شده‌اند را پیش از بازیابی backup جداگانه حفظ کنید. هیچ rollback یا حذف خودکار داده انجام نمی‌شود.
