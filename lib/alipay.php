<?php
// 支付宝沙箱支付：自实现 RSA2(SHA256WithRSA) 签名/验签与交易查询，不依赖 composer SDK。
// 密钥常量来自 Web 根目录外 private/my_project/secrets.php，禁止硬编码入库。
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

// 验签：$publicKey 可传 Base64 或 PEM（默认用支付宝公钥常量）；缺参/验签失败一律 false
function alipay_verify(array $params, $publicKey = null) {
    if (!isset($params['sign']) || !is_string($params['sign']) || $params['sign'] === '') return false;
    $content = alipay_sign_content($params);
    $pem = alipay_public_key_pem($publicKey !== null ? $publicKey : ALIPAY_ALIPAY_PUBLIC_KEY);
    $pub = openssl_pkey_get_public($pem);
    if ($pub === false) return false;
    $ok = openssl_verify($content, base64_decode($params['sign'], true), $pub, OPENSSL_ALGO_SHA256);
    return $ok === 1;
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
