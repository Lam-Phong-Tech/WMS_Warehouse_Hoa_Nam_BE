<?php

/**
 * DEV224-P1-04R — đồng bộ CHỈ nhóm path import SKU giữa spec sinh ra từ annotation và
 * artifact OpenAPI đang commit, rồi kiểm chứng lại được.
 *
 * Vì sao không commit thẳng kết quả `l5-swagger:generate`:
 *
 * Chạy generate tại thời điểm đóng WWHN-224 tạo ra 604 dòng khác biệt, trong đó phần lớn
 * KHÔNG thuộc WWHN-224 mà đến từ annotation health/readiness của một task đang làm dở
 * (nguồn của chúng còn nằm ở tệp CHƯA được theo dõi trong git). Commit trọn gói sẽ tạo ra
 * một artifact không tái lập được từ chính repo — người khác checkout đúng SHA đó rồi
 * generate lại sẽ ra spec khác — và gộp luôn công việc chưa xong của người khác vào
 * closure của mình. Cả hai đều là thứ mà register 18/08 đang phạt.
 *
 * Nên: lấy đúng các path `/api/v1/skus/import*` từ bản vừa sinh, ghép vào artifact đang
 * commit, giữ nguyên từng byte của phần còn lại.
 *
 * - JSON: ghép ở tầng cấu trúc (decode → gán → encode với đúng cờ mà l5-swagger dùng).
 * - YAML: ghép ở tầng KHỐI DÒNG. Symfony YAML dump không tái lập nguyên văn được file
 *   đang commit (thử 3 bộ tuỳ chọn đều lệch), nên re-dump cả file sẽ đổi định dạng hàng
 *   trăm chỗ không liên quan. Thay khối dòng của đúng path cần đổi là cách duy nhất giữ
 *   được phần còn lại nguyên vẹn.
 *
 * Dùng:
 *   php scripts/sync-wwhn224-import-openapi.php --fresh-dir=<dir> [--apply|--check]
 *
 * `--check` là cổng CI: exit code khác 0 nghĩa là annotation và artifact đã lệch nhau
 * (ví dụ ai đó đổi permission trong code mà quên đồng bộ hợp đồng công bố).
 */
const PATH_PREFIX = '/api/v1/skus/import';

$options = getopt('', ['fresh-dir:', 'target-dir::', 'apply', 'check']);
$freshDir = rtrim((string) ($options['fresh-dir'] ?? ''), '/\\');
$targetDir = rtrim((string) ($options['target-dir'] ?? 'storage/api-docs'), '/\\');
$mode = array_key_exists('check', $options) ? 'check' : 'apply';

if ($freshDir === '' || ! is_dir($freshDir)) {
    fwrite(STDERR, "Thiếu --fresh-dir (thư mục chứa api-docs.json/.yaml vừa sinh).\n");
    exit(2);
}

$problems = [];

// ── JSON ─────────────────────────────────────────────────────────────────────────
$freshJson = json_decode((string) file_get_contents($freshDir.'/api-docs.json'));
$targetJsonRaw = (string) file_get_contents($targetDir.'/api-docs.json');
$targetJson = json_decode($targetJsonRaw);

$keys = array_values(array_filter(
    array_keys((array) $freshJson->paths),
    static fn (string $key): bool => str_starts_with($key, PATH_PREFIX),
));
sort($keys);

if ($keys === []) {
    fwrite(STDERR, 'Không tìm thấy path nào bắt đầu bằng '.PATH_PREFIX." trong bản vừa sinh.\n");
    exit(2);
}

foreach ($keys as $key) {
    $freshEncoded = json_encode($freshJson->paths->{$key});
    $targetEncoded = isset($targetJson->paths->{$key}) ? json_encode($targetJson->paths->{$key}) : null;

    if ($freshEncoded === $targetEncoded) {
        continue;
    }

    $problems[] = "JSON path lệch: {$key}";
    $targetJson->paths->{$key} = $freshJson->paths->{$key};
}

if ($mode === 'apply' && $problems !== []) {
    // Đúng bộ cờ mà l5-swagger dùng; đã kiểm chứng round-trip nguyên văn trên chính
    // artifact đang commit (chỉ lệch ở object rỗng nếu decode thành mảng kết hợp, nên
    // ở đây decode thành object).
    $encoded = json_encode($targetJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents($targetDir.'/api-docs.json', $encoded.(str_ends_with($targetJsonRaw, "\n") ? "\n" : ''));
}

// ── YAML (ghép theo khối dòng) ───────────────────────────────────────────────────
$freshYaml = explode("\n", (string) file_get_contents($freshDir.'/api-docs.yaml'));
$targetYamlPath = $targetDir.'/api-docs.yaml';
$targetYaml = explode("\n", (string) file_get_contents($targetYamlPath));

/**
 * Trả về [dòng đầu, số dòng] của khối thuộc một path trong mảng dòng YAML.
 * Path là khoá thụt 2 space dưới `paths:`; khối kết thúc ở khoá kế tiếp cùng mức thụt.
 *
 * @param  array<int, string>  $lines
 * @return array{0: int, 1: int}|null
 */
function yamlBlock(array $lines, string $path): ?array
{
    // Symfony YAML bọc nháy đơn cho khoá có ký tự đặc biệt (`{id}` trong path template),
    // nên phải nhận cả hai dạng — nếu chỉ khớp dạng trần thì hai path có tham số sẽ âm
    // thầm không được kiểm, tức cổng `--check` báo xanh trên thứ nó chưa hề soi.
    $needles = ['  '.$path.':', "  '".$path."':"];
    $start = null;
    foreach ($lines as $index => $line) {
        if (in_array($line, $needles, true)) {
            $start = $index;
            break;
        }
    }
    if ($start === null) {
        return null;
    }

    $end = count($lines);
    for ($i = $start + 1; $i < count($lines); $i++) {
        $line = $lines[$i];
        if ($line === '' || str_starts_with($line, '    ')) {
            continue;
        }
        $end = $i;
        break;
    }

    return [$start, $end - $start];
}

// Ghép từ path cuối lên đầu để chỉ số dòng của các khối phía trước không bị xê dịch.
$yamlChanged = false;
foreach (array_reverse($keys) as $key) {
    $freshBlock = yamlBlock($freshYaml, $key);
    $targetBlock = yamlBlock($targetYaml, $key);

    if ($freshBlock === null) {
        $problems[] = "YAML: không tìm thấy khối vừa sinh cho {$key}";

        continue;
    }
    if ($targetBlock === null) {
        $problems[] = "YAML: artifact đang commit không có path {$key}";

        continue;
    }

    $freshLines = array_slice($freshYaml, $freshBlock[0], $freshBlock[1]);
    $targetLines = array_slice($targetYaml, $targetBlock[0], $targetBlock[1]);
    if ($freshLines === $targetLines) {
        continue;
    }

    $problems[] = "YAML path lệch: {$key}";
    array_splice($targetYaml, $targetBlock[0], $targetBlock[1], $freshLines);
    $yamlChanged = true;
}

if ($mode === 'apply' && $yamlChanged) {
    file_put_contents($targetYamlPath, implode("\n", $targetYaml));
}

// ── Kết quả ──────────────────────────────────────────────────────────────────────
foreach ($keys as $key) {
    echo "path: {$key}\n";
}

if ($problems === []) {
    echo "OK: artifact OpenAPI khớp annotation cho toàn bộ nhóm import SKU.\n";
    exit(0);
}

foreach ($problems as $problem) {
    echo ($mode === 'apply' ? 'ĐÃ ĐỒNG BỘ' : 'LỆCH').": {$problem}\n";
}

exit($mode === 'check' ? 1 : 0);
