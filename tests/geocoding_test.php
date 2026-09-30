<?php
require_once __DIR__.'/../lib/geocoding.php';

function check_geocoding($condition, $label) {
    if (!$condition) throw new RuntimeException($label);
    echo "PASS: {$label}\n";
}

$complete = reverse_geocode_address_suggestion([
    'countryName' => '中国',
    'principalSubdivision' => '湖南省',
    'city' => '长沙市',
    'locality' => '天心区',
    'suburb' => '青园街道'
]);
check_geocoding($complete === '中国湖南省长沙市天心区青园街道（定位点附近，请补充门牌号）', 'complete administrative address is formatted');

check_geocoding(
    reverse_geocode_address_suggestion(['locality' => '天心区']) === '天心区（定位点附近，请补充门牌号）',
    'locality alone creates an editable suggestion'
);
check_geocoding(
    reverse_geocode_address_suggestion(['city' => '长沙市']) === '长沙市（定位点附近，请补充门牌号）',
    'city alone creates an editable suggestion'
);
check_geocoding(
    reverse_geocode_address_suggestion(['city' => '长沙市', 'locality' => '长沙市']) === '长沙市（定位点附近，请补充门牌号）',
    'duplicate fields are removed in order'
);
check_geocoding(
    reverse_geocode_address_suggestion(['city' => '  ', 'locality' => ['天心区'], 'suburb' => null]) === '',
    'empty and non-string fields do not create an address'
);
