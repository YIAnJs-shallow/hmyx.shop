<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/shop.php';
expire_orders();
echo "Expired pending orders released.\n";
