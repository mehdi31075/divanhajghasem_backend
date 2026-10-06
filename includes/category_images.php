<?php
class DivanCategoryImages {
    private $directory;
    public function __construct($directory) { $this->directory = $directory; }
    public function save($file) {
        if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['size']) ||
            (int)$file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name']) ||
            (int)$file['size'] <= 0 || (int)$file['size'] > 5000000 ||
            !is_uploaded_file($file['tmp_name'])) {
            throw new DivanApiError(422, 'invalid_image', 'تصویر معتبر تا ۵ مگابایت انتخاب کنید.');
        }
        $info = @getimagesize($file['tmp_name']);
        $types = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif');
        if (!$info || !isset($types[$info[2]]) || $info[0] * $info[1] > 40000000) {
            throw new DivanApiError(422, 'invalid_image', 'تصویر باید JPEG، PNG یا GIF معتبر باشد.');
        }
        if (function_exists('random_bytes')) $bytes = random_bytes(16);
        else {
            $strong = false;
            $bytes = function_exists('openssl_random_pseudo_bytes') ? openssl_random_pseudo_bytes(16, $strong) : false;
            if (!$strong || $bytes === false) throw new RuntimeException('Secure random source unavailable');
        }
        $name = bin2hex($bytes).'.'.$types[$info[2]];
        if (!move_uploaded_file($file['tmp_name'], $this->directory.'/'.$name)) throw new RuntimeException('Image storage failed');
        @chmod($this->directory.'/'.$name, 0644);
        // Previous images remain for cached/old readers; no arbitrary unlink.
        return $name;
    }
}
