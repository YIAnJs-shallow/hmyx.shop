<?php
// Historical entrypoint retired. Payments must transition an existing owned order.
require_once __DIR__.'/lib/shop.php';
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) { http_response_code(410); exit('请从订单中心支付已有订单'); }
