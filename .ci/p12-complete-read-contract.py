"""Guarded P12 test-fixture correction and attachment GET hardening."""
from pathlib import Path
import hashlib
import runpy

helpers = runpy.run_path('.ci/p12-apply-read-contract.py')
replace_once = helpers['replace_once']
rewrite_method = helpers['rewrite_method']

def guarded(path, sha):
    raw = path.read_bytes()
    actual = hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()
    if actual != sha:
        raise RuntimeError(f'Source changed; refuse overwrite: {path} {actual}')
    return raw.decode()

path = Path('tests/Feature/P12DocumentsReadContractTest.php')
text = guarded(path, '987f15c3dcc679625b8e7b4a4749123643e6dd4f')
def fixture(s):
    start = s.index('        $sku = ')
    return s[:start] + r'''        $sku = $this->productSkuFixture('P12-'.Str::upper(Str::random(10)));
        if ($type === 'inbound') {
            // Create the correct canonical type from the start. PostgreSQL mirrors
            // forbid converting an issue with existing outbound lines to a receipt.
            $doc = \Modules\Warehouse\Models\StockDocument::create([
                'doc_type' => 'receipt', 'doc_no' => 'P12-IN-'.Str::upper(Str::random(12)),
                'doc_date' => '2026-10-08', 'status' => 'DRAFT', 'dst_warehouse_id' => $warehouse->id,
            ]);
            \Modules\Warehouse\Models\StockDocumentLine::create([
                'stock_document_id' => $doc->id, 'line_no' => 1, 'product_id' => $sku->sku_id,
                'qty_planned' => 1, 'qty_actual' => 0, 'scanned_qty' => 0,
            ]);

            return (string) $doc->id;
        }
        $request = $this->outboundRequestFixture($sku->sku_id, 1, 'P12-REQ-'.Str::upper(Str::random(10)));
        $doc = $this->outboundDocumentFixture($warehouse->id, $request);

        return $doc['document_id'];
    }

'''
text = rewrite_method(text, 'documentFixture', fixture)
text = replace_once(text, '        $this->assertSame($count, DB::table(\'outbound_item_matches\')->count());', '''        DB::table('outbound_item_matches')->where('id', $ids[0])->update(['status' => 'REMOVED']);
        $this->getJson(self::BASE.'outbound-documents/'.$id.'/scan-entries?status=ACTIVE&per_page=1', $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.summary.entry_count', 2)
            ->assertJsonPath('meta.summary.quantity_total', 2);
        $this->getJson(self::BASE.'outbound-documents/'.$id.'/scan-entries?status=REMOVED&per_page=1', $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.summary.entry_count', 1)
            ->assertJsonPath('data.0.id', $ids[0]);
        $this->assertSame($count, DB::table('outbound_item_matches')->count());''')
insert = r'''    public function test_attachment_reads_preserve_parent_metadata_and_failed_child_read_is_not_empty_parent(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        foreach (['inbound', 'outbound'] as $type) {
            $id = $this->documentFixture($warehouse, $type);
            $url = self::BASE.$type.'-documents/'.$id;
            $this->getJson($url.'/attachments?per_page=1', $this->headers($token))->assertOk()
                ->assertJsonPath('meta.document_id', $id)->assertJsonPath('meta.document_type', strtoupper($type))
                ->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
            $this->getJson($url.'/attachments?page=0', $this->headers($token))->assertStatus(422);
            $this->getJson(self::BASE.'document-attachments/'.Str::uuid().'/download', $this->headers($token))->assertNotFound();
            $this->getJson($url, $this->headers($token))->assertOk()->assertJsonPath('data.id', $id);
        }
    }

'''
text = replace_once(text, '    private function actorSession(): array\n', insert+'    private function actorSession(): array\n')
path.write_text(text)

path = Path('Modules/Warehouse/app/Http/Controllers/DocumentAttachmentController.php')
text = guarded(path, '25f6bc063c4489aa5e35d6aa786740f2b89f95a4')
def index(s):
    s = replace_once(s, '        $page = DB::table', '''        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
            'page_size' => ['sometimes', 'integer', 'between:1,200'],
            'cursor' => ['prohibited'],
        ]);
        $page = DB::table''')
    s = replace_once(s, "->orderByDesc('created_at')\n            ->paginate($this->perPage($request));", "->orderByDesc('created_at')\n            ->orderByDesc('id')\n            ->paginate($this->perPage($request))->appends($request->query());")
    s = replace_once(s, "        $body['meta']['requires_evidence'] = $requiresEvidence;", "        $body['meta']['requires_evidence'] = $requiresEvidence;\n        $body['meta']['document_id'] = $documentId;\n        $body['meta']['document_type'] = $documentType;")
    return replace_once(s, 'return $response->setData($body);', "return $response->setData($body)->header('Cache-Control', 'private, no-store');")
text = rewrite_method(text, 'indexPayload', index)
text = rewrite_method(text, 'download', lambda s: replace_once(s, "return Storage::disk($disk)->download($path, (string) $row->file_name);", "return Storage::disk($disk)->download($path, (string) $row->file_name, [\n            'Cache-Control' => 'private, no-store',\n            'X-Content-Type-Options' => 'nosniff',\n        ]);"))
path.write_text(text)
print('PATCHED attachment GET cache/paging/parent identity; existing type and warehouse ACL preserved')
