<?php

/**
 * DEV218-P2-01 hard gate — canonical stock write path.
 *
 * Bối cảnh: câu hỏi "giữ hay dọn" các class stock legacy đã được nêu lại ở
 * WWHN-190/195/205/207/218 mà không đóng được, vì mỗi lần chỉ ghi disposition
 * bằng văn bản. Script này biến quyết định đó thành ràng buộc chạy được:
 *
 *   1. Class trùng lặp đã xoá không được phép quay lại.
 *   2. Chỉ CurrentBaselineStockFlowController được giữ mutation route của
 *      inbound/outbound stock document.
 *   3. Các class legacy được RETAIN chỉ được phép lộ ra read-side (index/show);
 *      không được mọc lại method ghi tồn kho.
 *
 * Chạy độc lập, không cần boot Laravel/DB — phân tích nguồn nên dùng được cả
 * trong CI lẫn local trước khi commit.
 */
$root = dirname(__DIR__);
$failures = [];
$checks = [];

/** Class trùng lặp write-path đã xoá theo DEV218-P2-01. */
const FORBIDDEN_CLASSES = [
    'CurrentBaselineStockController' => 'Bản sao write-path thiếu toàn bộ control WWHN-218 (If-Match, optimistic version, lockDocument, label-printed gate, duplicate-item guard). Mutation phải đi qua CurrentBaselineStockFlowController.',
];

/**
 * Legacy được RETAIN: chỉ read-side. Value là các action được phép bind route.
 */
const READ_ONLY_CONTROLLERS = [
    'InboundDocumentController' => ['index', 'show'],
    'OutboundDocumentController' => ['index', 'show'],
];

/** Method mang hiệu ứng tồn kho — không được xuất hiện trên class read-side. */
const STOCK_MUTATING_METHODS = [
    'postReceipt', 'postIssue', 'reverseInbound', 'reverseOutbound',
    'inboundScan', 'outboundScan', 'outboundUnmatch', 'postStockDocument',
    'reverseStockDocument', 'scanStockDocument',
];

$controllerDir = $root.'/Modules/Warehouse/app/Http/Controllers';
$routeFile = $root.'/Modules/Warehouse/routes/api.php';

// --- Check 1: class bị cấm không được tồn tại lại ------------------------
foreach (FORBIDDEN_CLASSES as $class => $reason) {
    $path = $controllerDir.'/'.$class.'.php';
    if (is_file($path)) {
        $failures[] = "[REINTRODUCED] {$class}.php đã xuất hiện trở lại.\n    Lý do đã xoá: {$reason}";
    } else {
        $checks[] = "{$class} vắng mặt (đúng)";
    }
}

// --- Check 2: không còn tham chiếu nào tới class bị cấm ------------------
$scanRoots = ['Modules', 'app', 'routes', 'tests', 'config', 'database'];
$referenced = [];
foreach ($scanRoots as $dir) {
    $abs = $root.'/'.$dir;
    if (! is_dir($abs)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $contents = (string) file_get_contents($file->getPathname());
        foreach (array_keys(FORBIDDEN_CLASSES) as $class) {
            if (str_contains($contents, $class)) {
                $referenced[] = $class.' <- '.substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }
}
if ($referenced !== []) {
    $failures[] = "[DANGLING REFERENCE] Còn tham chiếu tới class đã xoá:\n    ".implode("\n    ", $referenced);
} else {
    $checks[] = 'Không còn tham chiếu tới class đã xoá';
}

// --- Check 3: mutation route phải thuộc StockFlowController --------------
$routes = (string) file_get_contents($routeFile);
$mutationPaths = [
    'post-receipt', 'post-issue', 'scan', 'scans',
    'reverse', 'reversals', 'unmatch',
];
$routePattern = '/Route::(post|patch|put|delete)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*\[\s*([A-Za-z0-9_]+)::class\s*,\s*[\'"]([A-Za-z0-9_]+)[\'"]/';
preg_match_all($routePattern, $routes, $matches, PREG_SET_ORDER);

$offending = [];
foreach ($matches as $match) {
    [, , $uri, $controller, $action] = $match;
    if (! str_contains($uri, 'inbound-documents') && ! str_contains($uri, 'outbound-documents')) {
        continue;
    }
    $segments = explode('/', $uri);
    $tail = end($segments);
    if (! in_array($tail, $mutationPaths, true)) {
        continue;
    }
    // Packing-label/RFID phụ trợ do controller chuyên trách xử lý, không phải
    // stock mutation của phiếu.
    if (str_contains($uri, 'packing-labels') || str_contains($uri, 'rfid')) {
        continue;
    }
    if (! in_array($controller, ['CurrentBaselineStockFlowController', 'AlignedOutboundFlowController'], true)) {
        $offending[] = "{$uri} -> {$controller}@{$action}";
    }
}
if ($offending !== []) {
    $failures[] = "[NON-CANONICAL MUTATION ROUTE] Route đổi tồn kho phải trỏ vào CurrentBaselineStockFlowController:\n    ".implode("\n    ", $offending);
} else {
    $checks[] = 'Mọi mutation route inbound/outbound đều thuộc write-path chuẩn';
}

// --- Check 4: controller read-side chỉ được bind action read ------------
$badBindings = [];
foreach ($matches as $match) {
    [, , $uri, $controller, $action] = $match;
    if (! array_key_exists($controller, READ_ONLY_CONTROLLERS)) {
        continue;
    }
    $badBindings[] = "{$uri} -> {$controller}@{$action} (chỉ được phép ".implode('/', READ_ONLY_CONTROLLERS[$controller]).')';
}
// Route::get đã bị pattern loại sẵn nên mọi hit ở trên đều là verb ghi.
if ($badBindings !== []) {
    $failures[] = "[READ-SIDE CONTROLLER BOUND TO WRITE VERB] Legacy read-side bị gán route ghi:\n    ".implode("\n    ", $badBindings);
} else {
    $checks[] = 'Controller legacy read-side không bị bind vào verb ghi';
}

// --- Check 5: class read-side không mọc lại method ghi tồn kho ----------
$grown = [];
foreach (array_keys(READ_ONLY_CONTROLLERS) as $class) {
    $path = $controllerDir.'/'.$class.'.php';
    if (! is_file($path)) {
        $failures[] = "[MISSING] {$class}.php không còn tồn tại nhưng vẫn được khai báo là RETAIN read-side.";

        continue;
    }
    $contents = (string) file_get_contents($path);
    foreach (STOCK_MUTATING_METHODS as $method) {
        if (preg_match('/function\s+'.preg_quote($method, '/').'\s*\(/', $contents)) {
            $grown[] = "{$class}::{$method}()";
        }
    }
}
if ($grown !== []) {
    $failures[] = "[WRITE METHOD ON READ-SIDE CLASS] Method đổi tồn kho xuất hiện trên class read-side:\n    ".implode("\n    ", $grown);
} else {
    $checks[] = 'Class legacy read-side không chứa method đổi tồn kho';
}

// --- Report -------------------------------------------------------------
$stamp = date('c');
echo "DEV218-P2-01 — canonical stock write path guard\n";
echo "checked_at: {$stamp}\n\n";

foreach ($checks as $ok) {
    echo "  PASS  {$ok}\n";
}

if ($failures !== []) {
    echo "\n";
    foreach ($failures as $failure) {
        echo "  FAIL  {$failure}\n";
    }
    echo "\nStatus: FAIL (".count($failures)." vi phạm)\n";
    echo "Tham chiếu: docs/decision-records/DEC-STK-002.md\n";
    exit(1);
}

echo "\nStatus: PASS\n";
exit(0);
