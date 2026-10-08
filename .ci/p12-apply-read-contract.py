"""One-time guarded repair; runs only in isolated CI, before tests and fast-forward push."""
from pathlib import Path
import hashlib
import re

ROOT = Path('Modules/Warehouse/app/Http/Controllers')

def replace_once(text, old, new):
    if text.count(old) != 1:
        raise RuntimeError(f'Expected one exact patch anchor, found {text.count(old)}: {old[:100]}')
    return text.replace(old, new, 1)

def rewrite_method(source, name, transform):
    match = re.search(r'^    (?:public|private|protected) function '+re.escape(name)+r'\b', source, re.M)
    if match is None:
        raise RuntimeError('Missing method: '+name)
    following = re.search(r'^    (?:public|private|protected) function ', source[match.end():], re.M)
    end = match.end() + following.start() if following else len(source)
    return source[:match.start()] + transform(source[match.start():end]) + source[end:]

for direction, expected in [('Inbound', 'd0f750ef7e121deb1c41aec2295a2d868d07bb49'), ('Outbound', 'abbb3f038784b740350dfc1a238355979c7a4bc5')]:
    path = ROOT / f'MiniApp{direction}Controller.php'
    raw = path.read_bytes()
    actual = hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()
    if actual != expected:
        raise RuntimeError(f'{path}: source changed; inspect/rebase instead of overwriting ({actual})')
    text = raw.decode()
    text = replace_once(text, 'use Modules\\Warehouse\\Models\\StockDocument;', 'use Modules\\Warehouse\\Models\\StockDocument;\nuse Modules\\Warehouse\\Support\\P12DocumentReadContract;')
    source = direction.upper()
    prefix = direction.lower()
    def index(s):
        s = replace_once(s, "$request->validate(['warehouse_id' => ['nullable', 'uuid']]);", 'P12DocumentReadContract::validateList($request);')
        s = replace_once(s, f"miniapp:{prefix}:list:p02-scope-sort-v1", f"miniapp:{prefix}:list:p12-read-v1")
        s = replace_once(s, "->orderByDesc('created_at')\n                    ->orderByDesc('id')", "->orderBy('created_at', $request->query('sort_order', 'desc'))\n                    ->orderBy('id', $request->query('sort_order', 'desc'))")
        s = replace_once(s, 'return $this->respondPaginated(new LengthAwarePaginator(', 'return P12DocumentReadContract::listResponse($this->respondPaginated(new LengthAwarePaginator(')
        s = replace_once(s, "['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],", "['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page', 'query' => $request->query()],")
        return replace_once(s, '        ));', f"        )), $request, '{source}');")
    text = rewrite_method(text, 'index', index)
    text = rewrite_method(text, 'indexQuery', lambda s: replace_once(s, '        return $query;', '        P12DocumentReadContract::applyCreatedRange($query, $request);\n\n        return $query;'))
    alias = 'e' if direction == 'Inbound' else 'm'
    query_method = 'scanEntriesQuery' if direction == 'Inbound' else 'scanEntryQuery'
    status = '$statusFilter' if direction == 'Inbound' else '$status'
    def evidence(s):
        s = replace_once(s, '    {\n', '    {\n        P12DocumentReadContract::validateEvidence($request);\n\n')
        s = replace_once(s, f"'miniapp:{prefix}:scan-entries'", f"'miniapp:{prefix}:scan-entries:p12-v1'")
        s = replace_once(s, f'                $page = $this->{query_method}($id, {status})\n', f'                $base = $this->{query_method}($id, {status});\n                $page = (clone $base)\n')
        s = replace_once(s, f"->orderByDesc('{alias}.created_at')\n                    ->paginate", f"->orderByDesc('{alias}.created_at')\n                    ->orderByDesc('{alias}.id')\n                    ->paginate")
        s = replace_once(s, "return ['items' => $page->items(), 'total' => $page->total()];", "return ['items' => $page->items(), 'total' => $page->total(), 'summary' => P12DocumentReadContract::evidenceSummary($base)];")
        s = replace_once(s, 'return $this->respondPaginated(new LengthAwarePaginator(', 'return P12DocumentReadContract::evidenceResponse($this->respondPaginated(new LengthAwarePaginator(')
        s = replace_once(s, "['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],", "['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page', 'query' => $request->query()],")
        return replace_once(s, '        ));', f"        )), $cached['summary'], $id, {status});")
    text = rewrite_method(text, 'scanEntries', evidence)
    def evidence_query(s):
        s = replace_once(s, "            's.sku_name',", "            's.sku_name',\n            's.unit',\n            'l.stock_document_id as document_id',")
        if direction == 'Inbound':
            s = replace_once(s, "            'l.product_id',", "            'l.product_id',\n            'l.product_id as sku_id',\n            'e.inbound_line_id as line_id',")
        else:
            s = replace_once(s, "            'm.matched_at',", "            'm.matched_at',\n            'm.created_at',")
        return s
    text = rewrite_method(text, query_method, evidence_query)
    path.write_text(text)
    print(f'PATCHED {path}: GET index/scanEntries and their private read queries only')

# PM decision 2026-10-08 supersedes the historical warehouse-local supplier lookup.
path = ROOT / 'P04InboundController.php'
raw = path.read_bytes()
actual = hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()
if actual != '17867ff9ca72140d8b82ca7f709fcc36e651bd8c':
    raise RuntimeError('P04 source changed; review instead of overwriting: '+actual)
text = raw.decode()
text = replace_once(text, "['scope' => 'WAREHOUSE', 'source' => 'inbound_suppliers', 'required' => false]", "['scope' => 'GLOBAL', 'source' => 'inbound_suppliers', 'required' => false]")
text = replace_once(text, "        if ($request->is('api/v1/mini-app/inbound/context')) {", "        if ($request->is('api/v1/mini-app/inbound/suppliers')) {\n            $rules = $extra; // GLOBAL lookup never accepts a warehouse_id query.\n        }\n        if ($request->is('api/v1/mini-app/inbound/context')) {")
def suppliers(s):
    s = replace_once(s, "            $scope = $this->scope($request, $input['warehouse_id'], 'read');\n            $query = DB::table('inbound_suppliers')->where('warehouse_id', $scope['warehouse_id'])\n                ->where('status', $input['status'] ?? 'ACTIVE');", """            $actor = User::whereKey($request->user()->user_id)->lockForUpdate()->first();
            if ($actor === null || $actor->status !== 'ACTIVE') {
                ApiError::abort(403, 'ACCESS_DENIED', 'Tài khoản không còn hoạt động.');
            }
            app(StableAuthSession::class)->requireId($actor);
            if (! $this->drafts->permissions($actor)['INBOUND']['read']) {
                ApiError::abort(403, 'P04_OPERATION_DENIED', 'Không có quyền tra cứu nhà cung cấp.');
            }
            $query = DB::table('inbound_suppliers')->where('status', $input['status'] ?? 'ACTIVE');""")
    return replace_once(s, "'warehouse_id' => $scope['warehouse_id'], 'source' => 'inbound_suppliers',", "'scope' => 'GLOBAL', 'source' => 'inbound_suppliers',")
text = rewrite_method(text, 'suppliers', suppliers)
text = replace_once(text, "DB::table('inbound_suppliers')->where('id', strtolower($id))->where('warehouse_id', $warehouseId)->lockForUpdate()->first();", "DB::table('inbound_suppliers')->where('id', strtolower($id))->lockForUpdate()->first();")
text = replace_once(text, 'Nhà cung cấp không tồn tại trong phạm vi kho này.', 'Nhà cung cấp không tồn tại trong danh mục chung.')
path.write_text(text)
print('PATCHED P04 supplier lookup/validation to GLOBAL; owner warehouse/session/write guards retained')

path = Path('tests/Feature/P04InboundContractTest.php')
raw = path.read_bytes()
actual = hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()
if actual != '270eaff7cd13d7835b07cedae7d141a8584d0f7d':
    raise RuntimeError('P04 tests changed; review instead of overwriting: '+actual)
text = raw.decode()
text = replace_once(text, "self::BASE.'/suppliers?warehouse_id='.$warehouse->id,", "self::BASE.'/suppliers',")
def supplier_test(s):
    s = replace_once(s, "self::BASE.'/suppliers?warehouse_id='.$warehouse->id;", "self::BASE.'/suppliers?status=ACTIVE';")
    s = replace_once(s, "->assertJsonPath('meta.total', 2)", "->assertJsonPath('meta.scope', 'GLOBAL')->assertJsonPath('meta.total', 3)")
    s = replace_once(s, "$url.'&status=INACTIVE'", "self::BASE.'/suppliers?status=INACTIVE'")
    return s.replace('test_supplier_search_filters_scope_status_literal_query_and_sql_pagination', 'test_supplier_search_is_global_with_status_literal_query_and_sql_pagination')
text = rewrite_method(text, 'test_supplier_search_filters_scope_status_literal_query_and_sql_pagination', supplier_test)
text = replace_once(text, "$supplier = $this->supplierRow($warehouse, 'Isolated P04 supplier');", "$supplierWarehouse = Warehouse::create(['code' => 'P04-GLOBAL-SUPPLIER', 'name' => 'Historical supplier origin', 'status' => 'INACTIVE']);\n        $supplier = $this->supplierRow($supplierWarehouse, 'Isolated P04 supplier');")
path.write_text(text)
print('PATCHED P04 tests for Oct 8 GLOBAL decision, including actual owner record/replay')
