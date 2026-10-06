<div dir="rtl" align="right">

# بررسی APK قدیمی — ۲۰۲۶/۱۰/۰۷

فایل ارسالی `DivanHajGhasem.apk` نسخهٔ 1.5 (versionCode 3)، با شناسهٔ `divan.ghasem.haj.hajghasemdivan`، minSdk 16 و targetSdk 25 بررسی شد. APK نصب، اجرا، بازسازی یا امضای مجدد نشد؛ بررسی از روی manifest و disassembly فایل classes.dex با ابزارهای Android SDK بود.

SHA-256 فایل: `7bd58c9df3b176be08568ca58b41b77bcf91660e146eb97a3845cb07f62b0b7b`.

## ناسازگاری مسیر

در Config، آدرس سرور با اسلش پایانی تعریف شده است. آدرس‌های ثابت داخل APK **دو اسلش** بعد از میزبان دارند:

<div dir="ltr" align="left">

```text
http://divanhajghasem.ir//api.php
http://divanhajghasem.ir//api.php?cat_id=
http://divanhajghasem.ir//api.php?nid=
http://divanhajghasem.ir//upload/category/
http://divanhajghasem.ir//upload/thumbs/
```

</div>

آزمایش قبلی آدرس `/api.php` این ناسازگاری را نشان نمی‌داد. با درخواست دقیق APK، `//api.php` و `//api.php?cat_id=61` روی HTTP پاسخ ۴۰۴ JSON از Laravel دادند؛ تصویر با دو اسلش پاسخ ۲۰۰ داشت. به `routes/divan.php` یک مسیر خواندنی برای اسلش اضافی اضافه شد که مستقیماً همان ReaderController و ApiTransport را اجرا می‌کند. تبدیل مسیر یا ریدایرکت HTTPS در برنامه اضافه نشد. GET و OPTIONS مجازند؛ POST همچنان ۴۰۵ است. مدیریت و احراز هویت تغییر نکردند.

## مسیر شکست در APK

`JsonUtils.getJSONString(String)` از `URL.openConnection()` و `HttpURLConnection.getResponseCode()` استفاده می‌کند. فقط در صورت کد ۲۰۰ بدنه خوانده می‌شود؛ در غیر این صورت مقدار برگشتی `null` است. در `HomeFragment$MyTask.onPostExecute(String)`، پس از بررسی اتصال اینترنت، نتیجه به `new JSONObject(result)` داده می‌شود؛ بررسی null وجود ندارد و فقط JSONException گرفته می‌شود. این مسیر با کرش گزارش‌شده هنگام دریافت دسته‌ها سازگار است. stack trace واقعی Android هنوز در دسترس نیست؛ اجرای APK روی گوشی یا شبیه‌ساز انجام نشد.

## وضعیت هاست و ریدایرکت

در ابتدای بررسی Force SSL خاموش و مسیر معمولی HTTP سالم بود. هنگام آزمون پس از نصب اصلاح، Nginx برای هر دو مسیر `/api.php` و `//api.php` پاسخ ۳۰۱ به HTTPS داد؛ بازخوانی DirectAdmin نشان داد گزینهٔ Force SSL اکنون روشن شده است. این تغییر به فایل مسیرهای Laravel مربوط نیست. کاربر در پاسخ به پیشنهاد خاموش‌کردن، صریحاً خواست **فعلاً تنظیم هاست تغییر نکند**؛ گزینه دست‌نخورده ماند.

در آزمون کوچک Java 17 با همان الگوی HttpURLConnection و followRedirects پیش‌فرض روشن، درخواست HTTP دقیق APK همچنان ۳۰۱ برگشت؛ منطق JsonUtils در این وضعیت null برمی‌گرداند. این آزمایش روی JVM سیستم بود، نه Android. تا زمانی که هاست این مسیر HTTP را ریدایرکت می‌کند، موفقیت اپ قدیمی قابل تأیید نیست.

## نصب و اعتبارسنجی

فقط فایل خصوصی `/home/divanhaj/domains/divanhajghasem.ir/divan/routes/divan.php` روی هاست به‌روزرسانی شد. پشتیبان نسخهٔ قبلی در `/private/tmp/divan-apk-review/routes-before-fix.php` ذخیره شد. دیتابیس و مطالب تغییر نکردند.

بررسی HTTPS با اعتبارسنجی استاندارد گواهی موفق بود (در بررسی‌های قبلی گواهی منقضی بود): `//api.php` پاسخ ۲۰۰ با ۳۲ دسته، `//api.php?cat_id=61` پاسخ ۲۰۰ با ۱۲ نوشته، و `//api.php?nid=85` پاسخ ۲۰۰ با یک نوشته دادند؛ هدر Location نداشتند.

۱۹ آزمون Unit با ۶۷ assertion موفق‌اند. ۷ آزمون مسیرخوانی، درخواست واقعی APK را از Kernel HTTP عبور می‌دهند و Repository را mock می‌کنند؛ به دیتابیس زنده وصل نمی‌شوند. پیش از اصلاح، ۵ مورد اسلش تکراری ۴۰۴ می‌دادند؛ پس از اصلاح موفق شدند. پاسخ ۲۰۰، قرارداد JSON، حفظ query و اولویت آن، نبود Location/Set-Cookie و رد POST بررسی شدند. Pint برای دو فایل PHP تغییرکرده نیز موفق بود. Featureهای وابسته به MySQL ایزوله اجرا نشدند.

</div>
