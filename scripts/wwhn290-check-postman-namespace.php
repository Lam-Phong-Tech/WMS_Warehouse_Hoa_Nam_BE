<?php

/*
 * WWHN-290 (DEV290-P1-03R) - kiem tra collection Postman chi goi dung mot namespace contract.
 *
 * Tach ra thanh file rieng thay vi nhung inline vao verifier: doan PHP nay co ca dau nhay don
 * lan dau nhay kep, nhung khi nhung vao `php -r '...'` trong bash thi rat de vo cu phap va bien
 * mot check that thanh mot check khong bao gio chay.
 *
 * Usage: php scripts/wwhn290-check-postman-namespace.php <collection.json> <required-prefix> [allowed-prefix ...]
 * Exit 0 khi moi request nam duoi <required-prefix>, tru cac prefix ngoai le duoc khai bao.
 */
$argvList = $argv ?? [];
$collectionPath = $argvList[1] ?? null;
$required = ltrim((string) ($argvList[2] ?? 'api/v1'), '/');
$allowed = array_map(static fn ($value) => ltrim((string) $value, '/'), array_slice($argvList, 3));

if ($collectionPath === null || ! is_file($collectionPath)) {
    fwrite(STDERR, "collection not found: {$collectionPath}\n");
    exit(1);
}

$document = json_decode(file_get_contents($collectionPath), true, 512, JSON_THROW_ON_ERROR);

$total = 0;
$outside = [];

$walk = static function (array $items) use (&$walk, &$total, &$outside, $required, $allowed): void {
    foreach ($items as $item) {
        if (isset($item['item']) && is_array($item['item'])) {
            $walk($item['item']);

            continue;
        }

        $path = $item['request']['url']['path'] ?? null;
        if (! is_array($path)) {
            continue;
        }

        $total++;
        $joined = implode('/', $path);

        if (str_starts_with($joined, $required)) {
            continue;
        }

        foreach ($allowed as $prefix) {
            if ($prefix !== '' && str_starts_with($joined, $prefix)) {
                continue 2;
            }
        }

        $outside[] = $joined;
    }
};

$walk($document['item'] ?? []);

if ($total === 0) {
    fwrite(STDERR, "collection declares no request\n");
    exit(1);
}

if ($outside !== []) {
    fwrite(STDERR, 'requests outside the approved namespace: '.implode(', ', array_slice($outside, 0, 5))."\n");
    exit(1);
}

echo "{$total} requests, all inside {$required}\n";
exit(0);
