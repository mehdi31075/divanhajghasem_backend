<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="divan-api" content="{{ url('/mobile-api.php') }}">
  <meta name="theme-color" content="#153f37">
  <title>دیوان · مدیریت محتوا</title>
  <link rel="icon" href="{{ asset('assets/panel/mark.svg') }}" type="image/svg+xml">
  <link rel="stylesheet" href="{{ asset('assets/ckeditor/ckeditor5.css') }}">
  <link rel="stylesheet" href="{{ asset('assets/panel/panel.css') }}?v=20261009-media-lib-v1">
  <script src="{{ asset('assets/purify/purify.min.js') }}" defer></script>
  <script src="{{ asset('assets/ckeditor/ckeditor5.umd.js') }}" defer></script>
  <script src="{{ asset('assets/ckeditor/fa.umd.js') }}" defer></script>
  <script type="module" src="{{ asset('assets/panel/app.js') }}?v=20261009-media-lib-v1"></script>
</head>
<body>
  <svg class="symbols" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><defs>
    <symbol id="i-book" viewBox="0 0 24 24"><path d="M12 6c-3-3-7-3-10-2v15c4-1 7-1 10 2 3-3 6-3 10-2V4c-3-1-7-1-10 2Z M12 6v15"/></symbol>
    <symbol id="i-home" viewBox="0 0 24 24"><path d="m3 10 9-8 9 8v11h-6v-7H9v7H3Z"/></symbol>
    <symbol id="i-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></symbol>
    <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="7" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3"/></symbol>
    <symbol id="i-arrow" viewBox="0 0 24 24"><path d="M20 12H4m6-6-6 6 6 6"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 4v16M4 12h16"/></symbol>
    <symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 4H3v16h6m3-8h9m-4-4 4 4-4 4"/></symbol>
    <symbol id="i-media" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></symbol>
    <symbol id="i-support" viewBox="0 0 24 24"><path d="M4 5h16v12H8l-4 4z"/><path d="M8 9h8m-8 4h5"/></symbol>
    <symbol id="i-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="4"/><path d="M2 21v-2a7 7 0 0 1 14 0v2M16 4a4 4 0 0 1 0 8m2 3a6 6 0 0 1 4 6"/></symbol>
  </defs></svg>
  <main id="login" class="login-layout">
    <section class="login-brand" aria-label="دیوان">
      <a class="wordmark" href="{{ url('/') }}"><span class="brand-icon"><svg><use href="#i-book"/></svg></span><span>دیوان<span class="brand-caption">خانهٔ واژه‌ها</span></span></a>
      <div class="brand-story"><span class="eyebrow">دیوان حاج قاسم</span><h1>هر واژه،<br>روایتی ماندگار.</h1><p>جایی برای نگهداری و انتشار نوشته‌ها،<br>و رساندن آن‌ها به دست خوانندگان.</p><div class="book-art" aria-hidden="true"><span></span><span></span><span></span><span></span></div></div>
      <small class="brand-foot">مدیریت نوشته‌ها و دسته‌بندی‌ها</small>
    </section>
    <section class="login-side"><div class="login-card">
      <span class="section-kicker">خوش آمدید</span><h2>ورود به مدیریت</h2><p class="muted">با حساب مدیر دیوان وارد شوید.</p>
      <form id="login-form">
        <label>نام کاربری<input name="username" autocomplete="username" placeholder="نام کاربری مدیر" required dir="ltr"></label>
        <label>رمز عبور<input name="password" type="password" autocomplete="current-password" placeholder="رمز عبور" required dir="ltr"></label>
        <p id="login-message" role="alert"></p><button class="primary" type="submit">ورود به دیوان <svg><use href="#i-arrow"/></svg></button>
      </form><p class="login-help">مطالعهٔ دیوان در اپ برای همه آزاد است.<br>این بخش مخصوص مدیریت محتواست.</p>
    </div></section>
  </main>
  <div id="panel" hidden>
    <aside class="sidebar">
      <a class="wordmark" href="#home" data-route="home"><span class="brand-icon"><svg><use href="#i-book"/></svg></span><span>دیوان<span class="brand-caption">مدیریت محتوا</span></span></a>
      <small class="nav-caption">فضای مدیریت</small>
      <nav aria-label="بخش‌های مدیریت">
        <a href="#home"><svg><use href="#i-home"/></svg><span>پیشخوان</span></a>
        <a href="#posts"><svg><use href="#i-book"/></svg><span>نوشته‌ها</span></a>
        <a href="#categories"><svg><use href="#i-grid"/></svg><span>دسته‌بندی‌ها</span></a>
        <a href="#pages"><svg><use href="#i-book"/></svg><span>صفحه‌های دیوان</span></a>
        <a href="#media"><svg><use href="#i-media"/></svg><span>کتابخانهٔ رسانه</span></a>
        <a href="#support"><svg><use href="#i-support"/></svg><span>پشتیبانی</span></a>
        <a href="#users"><svg><use href="#i-users"/></svg><span>کاربران اپ</span></a>
        <a href="#account"><svg><use href="#i-user"/></svg><span>حساب مدیر</span></a>
      </nav>
      <div class="sidebar-foot"><span class="avatar"><svg><use href="#i-user"/></svg></span><div><strong id="identity"></strong><small>مدیر دیوان</small></div><button id="logout" type="button" aria-label="خروج از حساب" title="خروج"><svg><use href="#i-logout"/></svg></button></div>
    </aside>
    <div class="workspace"><header class="topbar"><div><span class="section-kicker">دیوان حاج قاسم</span><strong>فضایی برای نوشته‌های ماندگار</strong></div><button class="primary compact" type="button" data-route="post-new"><svg><use href="#i-plus"/></svg>نوشتهٔ جدید</button></header>
      <main class="main-content"><p id="notice" role="status" aria-live="polite"></p><div id="content" aria-busy="false"></div><footer class="page-footer">دیوان <span>·</span> مدیریت نوشته‌ها</footer></main>
    </div>
  </div>
</body>
</html>
