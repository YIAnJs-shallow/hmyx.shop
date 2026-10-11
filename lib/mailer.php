<?php
// 免 composer 的 PHPMailer 加载（手动放置于 lib/PHPMailer/src/）
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * 读取 SMTP 配置：优先环境变量，其次 Web 根目录外的私有配置文件 secrets.php
 * 需要的常量/环境变量：SMTP_HOST SMTP_PORT SMTP_SECURE(ssl/tls/空) SMTP_USER SMTP_PASS MAIL_FROM MAIL_FROM_NAME
 * 返回 null 表示未配置。
 */
function smtp_config() {
    $cfg = [
        'host' => getenv('SMTP_HOST') ?: '',
        'port' => (int)(getenv('SMTP_PORT') ?: 0),
        'secure' => getenv('SMTP_SECURE') !== false ? getenv('SMTP_SECURE') : '',
        'user' => getenv('SMTP_USER') ?: '',
        'pass' => getenv('SMTP_PASS') !== false ? getenv('SMTP_PASS') : '',
        'from' => getenv('MAIL_FROM') ?: '',
        'from_name' => getenv('MAIL_FROM_NAME') ?: '惠蒙优选',
    ];
    if ($cfg['host'] === '' && $cfg['user'] === '') {
        $secretsPath = project_private_config_dir() . '/secrets.php';
        if (is_file($secretsPath)) {
            require_once $secretsPath;
            $cfg['host'] = $cfg['host'] ?: (defined('SMTP_HOST') ? SMTP_HOST : '');
            $cfg['port'] = $cfg['port'] ?: (defined('SMTP_PORT') ? (int)SMTP_PORT : 0);
            $cfg['secure'] = $cfg['secure'] ?: (defined('SMTP_SECURE') ? SMTP_SECURE : '');
            $cfg['user'] = $cfg['user'] ?: (defined('SMTP_USER') ? SMTP_USER : '');
            $cfg['pass'] = $cfg['pass'] ?: (defined('SMTP_PASS') ? SMTP_PASS : '');
            $cfg['from'] = $cfg['from'] ?: (defined('MAIL_FROM') ? MAIL_FROM : '');
            $cfg['from_name'] = $cfg['from_name'] ?: (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : '');
        }
    }
    if ($cfg['host'] === '' || $cfg['user'] === '' || $cfg['from'] === '') return null;
    if ($cfg['from_name'] === '') $cfg['from_name'] = '惠蒙优选';
    if (!in_array($cfg['secure'], ['ssl', 'tls', ''], true)) $cfg['secure'] = 'ssl';
    return $cfg;
}

/**
 * 发送邮件（失败抛异常，由调用方决定如何提示/记录）
 */
function send_mail($to, $subject, $htmlBody) {
    $cfg = smtp_config();
    if ($cfg === null) throw new RuntimeException('SMTP 未配置');
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $cfg['host'];
    $mail->Port = $cfg['port'] ?: 465;
    $mail->SMTPAuth = true;
    $mail->Username = $cfg['user'];
    $mail->Password = $cfg['pass'];
    if ($cfg['secure'] === 'ssl') $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    elseif ($cfg['secure'] === 'tls') $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($cfg['from'], $cfg['from_name']);
    $mail->addAddress($to);
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body = $htmlBody;
    $mail->AltBody = strip_tags($htmlBody);
    if (!$mail->send()) throw new RuntimeException('邮件发送失败');
    return true;
}
