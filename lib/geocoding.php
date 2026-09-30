<?php

function reverse_geocode_address_suggestion($data) {
    if (!is_array($data)) return '';
    $parts = [];
    foreach (['countryName', 'principalSubdivision', 'city', 'locality', 'suburb'] as $field) {
        $value = $data[$field] ?? null;
        if (!is_string($value)) continue;
        $value = trim($value);
        if ($value !== '' && !in_array($value, $parts, true)) $parts[] = $value;
    }
    if (!$parts) return '';
    $suffix = '（定位点附近，请补充门牌号）';
    $address = implode('', $parts);
    return mb_substr($address, 0, 255 - mb_strlen($suffix)) . $suffix;
}
