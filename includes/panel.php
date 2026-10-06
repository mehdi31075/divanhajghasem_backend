<?php
// Static panel shell. All data and writes require the JSON Bearer API.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(410);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":false,"error":"legacy_panel_retired","message":"Use mobile-api.php with a Bearer token"}';
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>مدیریت دیوان</title>
  <link rel="stylesheet" href="assets/panel/panel.css">
  <script src="assets/js/ckeditor/ckeditor.js" defer></script>
  <script type="module" src="assets/panel/app.js"></script>
</head>
<body>
  <main id="login" class="login-card">
    <p class="eyebrow">دیوان حاج قاسم</p>
    <h1>ورود به مدیریت</h1>
    <p>با حساب مدیر دیوان وارد شوید.</p>
    <form id="login-form">
      <label>نام کاربری<input name="username" autocomplete="username" required dir="ltr"></label>
      <label>رمز عبور<input name="password" type="password" autocomplete="current-password" required dir="ltr"></label>
      <p id="login-message" role="alert"></p>
      <button class="primary" type="submit">ورود</button>
    </form>
  </main>
  <div id="panel" hidden>
    <header class="topbar"><div><strong>مدیریت دیوان</strong><small id="identity"></small></div><button id="logout" type="button">خروج</button></header>
    <div class="layout">
      <nav aria-label="بخش‌های مدیریت">
        <a href="#home">پیشخوان</a>
        <a href="#posts">نوشته‌ها</a>
        <a href="#categories">دسته‌بندی‌ها</a>
        <a href="#account">حساب مدیر</a>
      </nav>
      <main><p id="notice" role="status" aria-live="polite"></p><div id="content"></div></main>
    </div>
  </div>
</body>
</html>
