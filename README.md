<div dir="rtl" align="right">

# دیوان — Laravel 13

بک‌اند و پنل فارسی دیوان روی Laravel 13.35، PHP 8.3+ و MySQL/MariaDB ساخته شده‌اند. پنل Blade و JavaScript، راست‌به‌چپ و واکنش‌گراست؛ فونت Vazirmatn، Quill و DOMPurify همراه پروژه‌اند و از CDN بارگیری نمی‌شوند. Node فقط برای آزمون/به‌روزرسانی بسته‌های پنل لازم است؛ خروجی آماده در `public/assets/` قرار دارد.

## سازگاری با اپ قدیمی

مسیر **`/api.php`** اکنون route لاراول است. خواندن آن بدون توکن و روی HTTP هم مجاز است:

- بدون query: دسته‌ها، ترتیب `cid DESC`.
- `?cat_id=61`: تمام مطالب دسته، ترتیب `nid ASC`.
- `?nid=117`: جزئیات مطلب.
- `?latest_news=20`: همان ترتیب صعودی نسخهٔ قدیمی و تعداد درخواستی.
- پاسخ: `{"AndroidEbookApp":[...]}` یا `[]`؛ نام و ترتیب فیلدها و مقادیر رشته‌ای حفظ شده‌اند. ورودی‌های نامعتبر به جای SQL خام، 422 می‌گیرند.
- مسیر تصاویر همچنان `/upload/category/<filename>` است.

`tbl_user`، `tbl_news_category` و `tbl_news` موجود استفاده می‌شوند؛ migration به جدول‌های موجود دست نمی‌زند و برای نصب خالی آن‌ها را می‌سازد. داده و حساب نمونهٔ ادمین seed نمی‌شود. migration rollback عمداً جدول‌های دیوان را حذف نمی‌کند؛ بازگشت به نسخهٔ قبلی با backup انجام می‌شود.

## ورود و مدیریت

قرارداد **`/mobile-api.php?action=...`** برای Flutter حفظ شده است. ورود و تمام عملیات مدیریت فقط روی HTTPS هستند. ورود با کاربر فعلی `tbl_user` انجام می‌شود؛ هش SHA-256 پنل قدیمی پذیرفته می‌شود. رمز تغییرکرده یا مدیر تازه از bcrypt استفاده می‌کند. توکن ۶۴ نویسه‌ای تصادفی، فقط به شکل SHA-256 در `divan_api_tokens` نگهداری می‌شود؛ پیش‌فرض ۲۴ ساعت اعتبار دارد. توکن‌های نسخهٔ قبلی همین API نیز تا انقضا و تا وقتی رمز عوض نشده معتبر می‌مانند.

Laravel یک guard اختصاصی Bearer دارد. هیچ `web` middleware، PHP session یا Cookie در احراز هویت این مسیرها استفاده نمی‌شود. session پنل قدیمی مجوز ایجاد، ویرایش یا حذف نیست. تغییر رمز، توکن‌های قبلی را نامعتبر می‌کند؛ logout فقط توکن جاری را حذف می‌کند. ده تلاش ناموفق در ۱۵ دقیقه محدود می‌شوند.

| روش | action | ورودی/نتیجه |
|---|---|---|
| POST | login | username, password → access_token, token_type, expires_in, username |
| GET | me | username |
| POST | logout | لغو توکن جاری |
| GET | بدون action | همان خواندن عمومی؛ latest_news حداکثر ۵۰۰ |
| POST | create / update | news_heading, news_date, cid, news_description؛ update دارای id |
| POST | delete | id؛ پاسخ nid و حذف دائمی مطابق قرارداد فعلی |
| GET | stats | تعداد واقعی نوشته‌ها و دسته‌ها |
| GET | posts | page, q, category_id؛ ۵۰ نوشته در هر صفحه |
| GET | account | Username, Email؛ هیچ رمز یا هشی برنمی‌گردد |
| POST | account_update | email؛ برای رمز: old_password, new_password, confirm_password |
| POST | category_create / category_update | category_name, author؛ تصویر category_image در multipart؛ update دارای id |
| POST | category_delete | id؛ فقط دستهٔ خالی قابل حذف است |

ایجاد دسته تصویر می‌خواهد؛ ویرایش بدون فایل، تصویر قبلی را حفظ می‌کند. JPEG/PNG/GIF واقعی تا ۵ مگابایت و ۴۰ میلیون پیکسل پذیرفته می‌شوند. نام تصادفی روی دیسک ثبت می‌شود؛ فایل PHP با پسوند تصویر رد می‌شود. فایل‌های قبلی حذف نمی‌شوند تا کش اپ قدیمی خراب نشود.

چهار فیلد نوشته الزامی‌اند. `news_date` عنوان فرعی است، تاریخ نیست. عنوان حداکثر ۵۰۰ نویسه، زیرعنوان ۲۵۵ و بدنهٔ API حداکثر ۵٬۰۰۰٬۰۰۰ بایت است؛ متن با UTF-8 سه‌بایتی جدول فعلی ذخیره می‌شود. زمان ایجاد/ویرایش تاریخی در دیتابیس قدیمی وجود ندارد و جعل نمی‌شود. این نسخه همچنان شناسهٔ idempotency و کنترل تعارض اتمیک ندارد؛ عملیات نامشخص فقط با GET بررسی می‌شود و POST خودکار تکرار نمی‌شود.

## پنل

ورود، پیشخوان با شمارش واقعی و تازه‌ترین نوشته‌ها، جستجو/فیلتر/صفحه‌بندی نوشته‌ها، مطالعهٔ ایزوله، ویرایشگر Quill، دسته‌های تصویری، ایجاد/ویرایش/حذف و حساب مدیر در پنل جدید قرار دارند. لینک‌های قدیمی پنل همین رابط را باز می‌کنند؛ POST فرم‌های قبلی 410 می‌گیرد.

HTML قدیمی تا اولین ویرایش واقعی متن دقیقاً حفظ می‌شود؛ تغییر عنوان یا دسته آن را بازنویسی نمی‌کند. ورودی و خروجی Quill با DOMPurify پاک‌سازی می‌شود. پیش‌نمایش HTML داخل iframe با sandbox بدون اسکریپت قرار دارد. متن فرم هنگام انقضای ورود باقی می‌ماند و پس از ورود دوباره قابل ادامه است. توکن پنل فقط در sessionStorage تب است؛ رمز ذخیره نمی‌شود. اطلاعات پنل همگی از API دریافت می‌شوند.

Quill 2.0.3 یک advisory برای خروجی HTML دارد (GHSA-v3m3-f69x-jf25). پاک‌سازی DOMPurify قبل از ورود به ویرایشگر و پس از `getSemanticHTML` اعمال شده؛ نمایش هم sandbox است. `npm audit` همچنان advisory بستهٔ بالادستی را گزارش می‌کند؛ این گزارش پنهان یا با downgrade نامطمئن حذف نشده است.

## اتصال روی هاست فعلی

فایل `divanhaj_db.sql` مربوط به MariaDB 10.6.24 با جدول‌های MyISAM و charset `utf8mb3_general_ci` است. بک‌اند فقط اتصال MySQL/MariaDB دارد؛ SQLite از اجرای بک‌اند و آزمون‌های دیتابیس حذف شده است. تنظیمات `.env.example` برای همان هاست هستند:

<div dir="ltr" align="left">

```dotenv
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=divanhaj_db
DB_USERNAME=divanhaj_db
DB_PASSWORD=your_private_database_password
DB_CHARSET=utf8mb3
DB_COLLATION=utf8mb3_general_ci
```

</div>

رمز واقعی فقط در `.env` خصوصی قرار می‌گیرد و همراه dump وارد Git نمی‌شود. `localhost` به خود هاست اشاره دارد، نه این سیستم توسعه. دیتابیس زنده از قبل موجود است؛ فایل SQL را روی آن دوباره import نکنید. ستون `news_description` در dump از نوع `LONGTEXT` است؛ ساخت دیتابیس خالی نیز همین نوع را دارد و بدنهٔ API حداکثر ۵٬۰۰۰٬۰۰۰ بایت می‌پذیرد. migration جدول موجود را تغییر نمی‌دهد.

<div dir="ltr" align="left">

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
cp .env.example .env
# Set the private database password in .env on the hosting server.
php artisan key:generate --force
php artisan migrate --force
php artisan divan:check
php artisan optimize
```

</div>

این دستورها برای هاست‌اند و هیچ سرور محلی اجرا نمی‌کنند. راهنمای backup و document root در [DEPLOYMENT.md](DEPLOYMENT.md) است. حساب موجود مدیر استفاده می‌شود؛ `divan:create-admin` فقط برای دیتابیس خالی لازم است.

## توسعه و آزمون

آزمون‌های دیتابیس فقط با یک دیتابیس **جدا و قابل پاک‌شدن** به نامی که به `_test` ختم می‌شود اجرا می‌شوند. کاربر MySQL تست فقط به همین دیتابیس دسترسی داشته باشد. پیش از migration/truncate، نام نهایی اتصال (حتی در DB_URL)، محیط `testing` و رضایت صریح `DIVAN_ALLOW_DATABASE_TESTS=true` بررسی می‌شوند. تست‌های Feature بدون این تنظیمات skip می‌شوند؛ دو اسکریپت API نیز از اجرا خودداری می‌کنند. `.env.testing.example` را به `.env.testing` کپی و مشخصات این دیتابیس جدا را وارد کنید.

<div dir="ltr" align="left">

```sh
composer install
cp .env.testing.example .env.testing
# Configure a dedicated MySQL/MariaDB test database; never use the live database.
php artisan config:clear
php artisan key:generate --env=testing
php artisan test
php tests/mobile_api_test.php
php tests/panel_api_test.php
npm ci
npm run build
npm test
```

</div>

در این سیستم PHP لاراول در `/opt/homebrew/opt/php@8.3/bin/php` است. MySQL تست در این سیستم موجود نیست؛ اجرای CRUD و اتصال واقعی پس از تغییر به MySQL هنوز تأیید نشده است. نتیجهٔ قبلی ۱۰ تست HTTP با ۱۴۴ assertion، ۴۶ بررسی API و ۳۱ بررسی مدیریت مربوط به fixture SQLite پیش از این تغییر بود. در بررسی جدید ۶ آزمون مستقل محافظ دیتابیس (۷ assertion) و ۱۳ تست پنل موفق شدند؛ ۱۱ تست HTTP به‌دلیل نبود دیتابیس مستقل MySQL اجرا نشدند. Chrome واقعی با fixture پیش‌تر بررسی شده است.

**استقرار:** [DEPLOYMENT.md](DEPLOYMENT.md). کد روی GitHub است؛ پنل اکنون روی سایت اصلی با چیدمان `divan` و `public_html` باز می‌شود. ورود واقعی، نمایش ۳۲۱ نوشته و ۳۲ دسته، مطالعهٔ یک نوشته و بارگیری ۳۲ تصویر دسته روی هاست بررسی شدند. ایجاد، ویرایش و حذف محتوای واقعی در این بررسی اجرا نشدند. صفحهٔ HTTPS در Chrome باز شد، اما بررسی مستقل اعتبار گواهی هنوز خطای certificate has expired دارد؛ پیش از اتصال عادی Flutter، گواهی معتبر نصب شود.

</div>
