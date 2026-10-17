<?php
// 支付宝沙箱支付：自实现 RSA2(SHA256WithRSA) 签名/验签与交易查询，不依赖 composer SDK。
// 密钥常量来自 Web 根目录外 private/my_project/secrets.php，禁止硬编码入库。
// 本文件是密钥常量的首个加载点：主动引入 secrets.php（幂等），保证 pay.php 直连链路可用。
if (!defined('ALIPAY_SANDBOX_APP_ID')) {
    $alipaySecretsPath = project_private_config_dir() . '/secrets.php';
    if (is_file($alipaySecretsPath)) require_once $alipaySecretsPath;
}
// 站点对外地址（含中文域名的 punycode 形式），用于 return_url / notify_url
define('ALIPAY_SITE_BASE', 'https://xn--4oq564b47zxtj');

function alipay_private_key_resource() {
    $key = trim(ALIPAY_APP_PRIVATE_KEY);
    $pem = "-----BEGIN PRIVATE KEY-----\n" . chunk_split($key, 64, "\n") . "-----END PRIVATE KEY-----";
    $res = openssl_pkey_get_private($pem);
    if ($res === false) throw new RuntimeException('支付宝应用私钥格式无效');
    return $res;
}

// 将 Base64 单行公钥规范化为 PEM（可透传已是 PEM 的文本）
function alipay_public_key_pem($key) {
    $key = trim((string)$key);
    if (strpos($key, '-----BEGIN') !== false) return $key;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($key, 64, "\n") . "-----END PUBLIC KEY-----";
}

// 参与签名的参数串：按 key ASCII 升序 k=v&，剔除空值与 sign/sign_type（值不做 urlencode）
function alipay_sign_content(array $params) {
    $filtered = [];
    foreach ($params as $k => $v) {
        if ($k === 'sign' || $k === 'sign_type' || $v === '' || $v === null) continue;
        $filtered[(string)$k] = (string)$v;
    }
    ksort($filtered, SORT_STRING);
    $pairs = [];
    foreach ($filtered as $k => $v) $pairs[] = $k . '=' . $v;
    return implode('&', $pairs);
}

// 签名：附入 sign(base64) 与 sign_type=RSA2，返回完整参数数组
function alipay_sign(array $params) {
    $content = alipay_sign_content($params);
    $signature = '';
    if (!openssl_sign($content, $signature, alipay_private_key_resource(), OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('支付宝签名失败');
    }
    $params['sign'] = base64_encode($signature);
    $params['sign_type'] = 'RSA2';
    return $params;
}

// 验签：$publicKey 可传 Base64 或 PEM（默认用支付宝公钥常量；环境变量 ALIPAY_VERIFY_KEY_OVERRIDE 仅用于集成测试注入应用公钥自验）；缺参/验签失败一律 false
function alipay_verify(array $params, $publicKey = null) {
    if (!isset($params['sign']) || !is_string($params['sign']) || $params['sign'] === '') return false;
    $content = alipay_sign_content($params);
    $defaultKey = getenv('ALIPAY_VERIFY_KEY_OVERRIDE') ?: ALIPAY_ALIPAY_PUBLIC_KEY;
    $pem = alipay_public_key_pem($publicKey !== null ? $publicKey : $defaultKey);
    $pub = openssl_pkey_get_public($pem);
    if ($pub === false) return false;
    $ok = openssl_verify($content, base64_decode($params['sign'], true), $pub, OPENSSL_ALGO_SHA256);
    return $ok === 1;
}

// 网关地址：环境变量 ALIPAY_GATEWAY_OVERRIDE 仅用于集成测试注入不可达地址，正常运行取 secrets.php 的 ALIPAY_GATEWAY
function alipay_gateway() {
    $override = getenv('ALIPAY_GATEWAY_OVERRIDE');
    return (is_string($override) && $override !== '') ? $override : ALIPAY_GATEWAY;
}

// 向网关发送同一签名请求（GET）：curl 失败或响应含 invalid-signature 返回 false，否则返回响应体
function alipay_gateway_probe(array $params) {
    $url = alipay_gateway() . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        // phpStudy 环境若缺 CA 报错可回退关闭校验（仅限沙箱）
        $err = curl_error($ch);
        curl_close($ch);
        error_log('alipay_gateway_probe curl: ' . $err . '，回退关闭 SSL 校验（仅限沙箱）');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        if ($body === false) { curl_close($ch); return false; }
    }
    curl_close($ch);
    return (string)$body;
}

// 预检：向网关发送同一签名请求，invalid-signature 时换新时间戳重签重试
// 返回：通过的最终参数数组（含最终 timestamp/sign）；全部失败返回 null
function alipay_signed_request_with_retry(array $baseParams, int $maxAttempts = 8): ?array {
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        if ($attempt > 0) usleep(300000);
        $params = $baseParams;
        $params['timestamp'] = date('Y-m-d H:i:s');
        $params = alipay_sign($params);
        $body = alipay_gateway_probe($params);
        if ($body !== false && strpos($body, 'invalid-signature') === false) return $params;
    }
    return null;
}

// 主动查询交易状态（alipay.trade.query）：返回 trade_status 字符串，查询失败/无此单返回 null
function alipay_query_trade($orderNo) {
    $params = [
        'app_id' => ALIPAY_SANDBOX_APP_ID,
        'method' => 'alipay.trade.query',
        'format' => 'JSON',
        'charset' => 'utf-8',
        'sign_type' => 'RSA2',
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => '1.0',
        'biz_content' => json_encode(['out_trade_no' => $orderNo], JSON_UNESCAPED_UNICODE),
    ];
    $params = alipay_sign($params);
    // 沙箱网关仅支持 GET；TLS 校验按系统 CA 开启，phpStudy 环境若缺 CA 报错可临时关闭校验（仅限沙箱）
    $ch = curl_init(ALIPAY_GATEWAY . '?' . http_build_query($params));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    if ($body === false) { curl_close($ch); error_log('alipay_query_trade curl: ' . curl_error($ch)); return null; }
    curl_close($ch);
    $data = json_decode($body, true);
    $response = $data['alipay_trade_query_response'] ?? null;
    if (!is_array($response) || (isset($response['code']) && $response['code'] !== '10000')) {
        if (is_array($response)) error_log('alipay_query_trade: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
        return null;
    }
    return $response['trade_status'] ?? null;
}

// 构造 alipay.trade.refund 请求参数（签名路径与 alipay_query_trade 一致）：供退款函数与测试断言共用
function alipay_refund_params($outTradeNo, $refundAmountCents, $outRequestNo) {
    $params = [
        'app_id' => ALIPAY_SANDBOX_APP_ID,
        'method' => 'alipay.trade.refund',
        'format' => 'JSON',
        'charset' => 'utf-8',
        'sign_type' => 'RSA2',
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => '1.0',
        'biz_content' => json_encode([
            'out_trade_no' => (string)$outTradeNo,
            'refund_amount' => money((int)$refundAmountCents),
            'out_request_no' => (string)$outRequestNo,
        ], JSON_UNESCAPED_UNICODE),
    ];
    return alipay_sign($params);
}

// 沙箱退款（alipay.trade.refund）：fund_change=Y 视为成功；失败返回 false 并记日志（不做预检重试，网关恢复后由 cron 重扫）
function alipay_trade_refund($outTradeNo, $refundAmountCents, $outRequestNo) {
    $params = alipay_refund_params($outTradeNo, $refundAmountCents, $outRequestNo);
    $ch = curl_init(alipay_gateway() . '?' . http_build_query($params));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        error_log('alipay_trade_refund curl: ' . $err);
        return false;
    }
    curl_close($ch);
    $data = json_decode($body, true);
    $response = $data['alipay_trade_refund_response'] ?? null;
    if (!is_array($response) || (isset($response['code']) && $response['code'] !== '10000') || ($response['fund_change'] ?? '') !== 'Y') {
        error_log('alipay_trade_refund failed: ' . substr((string)$body, 0, 500));
        return false;
    }
    return true;
}
