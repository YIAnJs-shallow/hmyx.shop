<?php
require_once __DIR__ . '/lib/security.php';

// 1. 生成 4 位随机验证码字符串
$code = '';
$chars = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
for ($i = 0; $i < 4; $i++) {
    $code .= $chars[mt_rand(0, strlen($chars) - 1)];
}

// 2. 存入 Session 供注册/登录校验使用（统一转为小写，忽略大小写敏感）
// ?scope=admin 时写入独立键，避免前后台登录页验证码互相覆盖
if (($_GET['scope'] ?? '') === 'admin') {
    $_SESSION['captcha_admin'] = strtolower($code);
} else {
    $_SESSION['captcha_code'] = strtolower($code);
}

// 3. 创建验证码图片画布
header('Content-Type: image/png');
$image = imagecreatetruecolor(100, 38);
$bg_color = imagecolorallocate($image, 245, 247, 250);
$text_color = imagecolorallocate($image, 37, 99, 235);
$noise_color = imagecolorallocate($image, 203, 213, 225);

imagefill($image, 0, 0, $bg_color);

// 4. 添加干扰点/干扰线
for ($i = 0; $i < 5; $i++) {
    imageline($image, rand(0, 100), rand(0, 38), rand(0, 100), rand(0, 38), $noise_color);
}

// 5. 写入字符
imagestring($image, 5, 32, 10, $code, $text_color);

// 6. 输出图片并销毁资源
imagepng($image);
imagedestroy($image);
?>