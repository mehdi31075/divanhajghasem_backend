<?php
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo 'این فرم قدیمی غیرفعال شده است. از پنل index.php استفاده کنید.';
exit;
