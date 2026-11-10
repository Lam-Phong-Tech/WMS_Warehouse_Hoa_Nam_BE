# Thêm trường mô tả cho nhà cung cấp — Kế hoạch triển khai

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Thêm trường `description` (tối đa 500 ký tự) xuyên suốt DB, API P04 và màn Danh sách NCC.

**Architecture:** Thêm một cột nullable trên `inbound_suppliers`; mở rộng validation/response của write API và tìm kiếm của read API; FE thêm textarea trong form, cột trong bảng, và đọc `description` theo kiểu chịu được response cũ. Không đổi ETag, mã NCC, quyền hay optimistic lock.

**Tech Stack:** Laravel 12 / PHPUnit / PostgreSQL-SQLite; React 19 + TypeScript + Zod 4 + TanStack Query + MUI; Vitest + React Testing Library.

**Spec:** `WMS_Warehouse_Hoa_Nam_BE/docs/superpowers/specs/2026-11-10-supplier-description-design.md`

## Global Constraints

- Giới hạn mô tả: **500 ký tự** (không phải 1000). Rỗng sau trim → `null`.
- Cột DB: `string('description', 500)->nullable()`, không dùng `after()`.
- API: `description` tùy chọn ở POST/PATCH; `nullable|string|max:500`; field lạ vẫn bị từ chối; body vẫn ≤ 16 KiB.
- Không đổi `display_name`, mã NCC, `version`/ETag, quyền, mã lỗi.
- FE phải chấp nhận response thiếu `description` (coi là `null`) để deploy BE/FE không thứ tự.
- Không sửa các file FE đang có thay đổi của người dùng (`src/modules/reverse/...`, `src/modules/warranty/...`, `src/shared/ui/components/UiSelect.tsx`).
- Commit chỉ các file thuộc thay đổi này; BE và FE là hai repo riêng.

## Review Focus

- **500 vs 501 ký tự:** 500 phải lưu được, 501 phải bị 422 và FE chặn trước khi gọi API.
- **Chuỗi chỉ có khoảng trắng:** phải lưu `null`, không lưu chuỗi rỗng.
- **NCC cũ / response cũ:** `description = null` hoặc thiếu hẳn phải render “—” mà không làm vỡ schema.
- **Tiếng Việt/Trung và emoji:** trim và đếm theo ký tự; tìm kiếm theo mô tả có dấu vẫn đúng.
- **Mô tả rất dài, không có khoảng trắng:** không được kéo giãn bảng; hiển thị tối đa 2 dòng + `title` đầy đủ.

---

### Task 1: Backend — migration, write API, tìm kiếm, OpenAPI

**Files:**
- Create: `WMS_Warehouse_Hoa_Nam_BE/database/migrations/2026_11_10_000001_add_description_to_inbound_suppliers.php`
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Warehouse/app/Http/Controllers/SupplierWriteController.php`
- Modify: `WMS_Warehouse_Hoa_Nam_BE/Modules/Warehouse/app/Http/Controllers/P04InboundController.php:107-127`
- Modify: `WMS_Warehouse_Hoa_Nam_BE/app/Swagger/P04SupplierDirectoryOpenApi.php`
- Modify: `WMS_Warehouse_Hoa_Nam_BE/app/Swagger/SupplierWriteOpenApi.php`
- Test: `WMS_Warehouse_Hoa_Nam_BE/tests/Feature/SupplierWriteApiTest.php`
- Test: `WMS_Warehouse_Hoa_Nam_BE/tests/Feature/P04InboundContractTest.php`

**Interfaces:**
- Consumes: bảng `inbound_suppliers`, route hiện có `GET/POST/PATCH /api/v1/mini-app/inbound/suppliers`.
- Produces: response row có `description: string|null`; request POST/PATCH nhận `description?: string|null`; `q` khớp `code`, `name`, `description`.

- [ ] **Step 1: Viết test BE thất bại**

Thêm vào `SupplierWriteApiTest`:

```php
public function test_description_is_optional_trimmed_limited_and_searchable(): void
{
    $headers = $this->actor();
    $created = $this->postJson(self::URL, [
        'name' => 'Có mô tả', 'description' => '  Nhà cung cấp linh kiện  ',
    ], $headers)->assertCreated()
        ->assertJsonPath('data.description', 'Nhà cung cấp linh kiện');

    $this->postJson(self::URL, ['name' => 'Không mô tả'], $headers)->assertCreated()
        ->assertJsonPath('data.description', null);
    $this->postJson(self::URL, ['name' => 'Mô tả trống', 'description' => '   '], $headers)
        ->assertCreated()->assertJsonPath('data.description', null);
    $this->postJson(self::URL, ['name' => 'Mô tả dài', 'description' => str_repeat('a', 501)], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('description');
    $this->postJson(self::URL, ['name' => 'Mô tả sai kiểu', 'description' => ['nested']], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('description');
    $this->postJson(self::URL, ['name' => 'Mô tả tối đa', 'description' => str_repeat('a', 500)], $headers)
        ->assertCreated()->assertJsonPath('data.description', str_repeat('a', 500));

    $this->getJson(self::URL.'?q=linh kiện', $headers)->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $created->json('data.id'))
        ->assertJsonPath('data.0.description', 'Nhà cung cấp linh kiện');
}

public function test_update_can_change_only_description_and_clear_it(): void
{
    $headers = $this->actor();
    $id = $this->supplier();
    $this->patchJson(self::URL.'/'.$id, ['description' => '  Mô tả mới  '], $headers + ['If-Match' => '"1"'])
        ->assertOk()->assertJsonPath('data.description', 'Mô tả mới')->assertJsonPath('data.version', 2);
    $this->patchJson(self::URL.'/'.$id, ['description' => null], $headers + ['If-Match' => '"2"'])
        ->assertOk()->assertJsonPath('data.description', null)->assertJsonPath('data.version', 3);
    $this->patchJson(self::URL.'/'.$id, ['description' => str_repeat('a', 501)], $headers + ['If-Match' => '"3"'])
        ->assertUnprocessable()->assertJsonValidationErrors('description');
}
```

Sửa `P04InboundContractTest`:

```php
$a = $this->supplierRow($warehouse, 'Alpha 100%');
$this->supplierRow($warehouse, 'Alpha 200');
$described = $this->supplierRow($warehouse, 'Beta', 'ACTIVE', 'Mô tả đặc biệt');
// ...
$this->getJson($url.'&q=đặc biệt', $this->headers($token))->assertOk()
    ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $described)
    ->assertJsonPath('data.0.description', 'Mô tả đặc biệt');
```

Đổi helper cuối file thành:

```php
private function supplierRow(Warehouse $warehouse, string $name, string $status = 'ACTIVE', ?string $description = null): string
{
    $id = (string) Str::uuid();
    DB::table('inbound_suppliers')->insert([
        'id' => $id, 'warehouse_id' => (string) $warehouse->id, 'name' => $name,
        'description' => $description, 'status' => $status, 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}
```

- [ ] **Step 2: Chạy test để xác nhận thất bại**

Run (trong `WMS_Warehouse_Hoa_Nam_BE`):

```powershell
php artisan test tests/Feature/SupplierWriteApiTest.php tests/Feature/P04InboundContractTest.php
```

Expected: FAIL — `UNEXPECTED_FIELD`/validation ở test mô tả, và lỗi thiếu cột `description` ở test P04.

- [ ] **Step 3: Tạo migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_suppliers', function (Blueprint $table): void {
            $table->string('description', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inbound_suppliers', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
```

- [ ] **Step 4: Mở rộng `SupplierWriteController`**

Trong `input()`:

```php
if (array_diff(array_keys($input), ['name', 'description', 'status']) !== []) {
    ApiError::abort(422, 'UNEXPECTED_FIELD', 'Chỉ được gửi tên, mô tả và trạng thái nhà cung cấp.');
}
if ($update && $input === []) {
    ApiError::abort(422, 'EMPTY_UPDATE', 'Cần gửi tên, mô tả hoặc trạng thái để cập nhật.');
}
if (isset($input['name']) && is_string($input['name'])) {
    $input['name'] = trim($input['name']);
}
if (array_key_exists('description', $input) && is_string($input['description'])) {
    $input['description'] = trim($input['description']);
    if ($input['description'] === '') {
        $input['description'] = null;
    }
}

return Validator::make($input, [
    'name' => $update ? ['sometimes', 'required', 'string', 'max:200'] : ['required', 'string', 'max:200'],
    'description' => ['sometimes', 'nullable', 'string', 'max:500'],
    'status' => ['sometimes', 'required', Rule::in(['ACTIVE', 'INACTIVE'])],
])->validate();
```

Trong `store()` thêm `'description' => $input['description'] ?? null` vào `$row`.
Trong `response()` thêm `'description' => $supplier->description` sau `name`.

- [ ] **Step 5: Mở rộng danh sách và tìm kiếm P04**

Trong `P04InboundController::suppliers()`:

```php
$query->where(function ($query) use ($pattern): void {
    $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
        ->orWhereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$pattern])
        ->orWhereRaw("LOWER(description) LIKE ? ESCAPE '!'", [$pattern]);
});
```

và thêm `description` vào danh sách cột `->get([...])`.

- [ ] **Step 6: Cập nhật OpenAPI**

- `P04SupplierDirectoryOpenApi`: thêm `'description'` vào `required`; thêm property `description` type string, `nullable: true`; sửa mô tả `q` thành “code, name or description”.
- `SupplierWriteOpenApi`: thêm `description` (`type: string, maxLength: 500, nullable: true`) vào request body POST và PATCH; cập nhật summary/description PATCH thành name/description/status; cập nhật mô tả lỗi 422.

- [ ] **Step 7: Chạy test để xác nhận pass**

```powershell
php artisan test tests/Feature/SupplierWriteApiTest.php tests/Feature/P04InboundContractTest.php
```

Expected: PASS toàn bộ.

- [ ] **Step 8: Format và commit BE**

```powershell
php vendor/bin/pint --dirty
git add database/migrations/2026_11_10_000001_add_description_to_inbound_suppliers.php Modules/Warehouse/app/Http/Controllers/SupplierWriteController.php Modules/Warehouse/app/Http/Controllers/P04InboundController.php app/Swagger/P04SupplierDirectoryOpenApi.php app/Swagger/SupplierWriteOpenApi.php tests/Feature/SupplierWriteApiTest.php tests/Feature/P04InboundContractTest.php docs/superpowers/specs/2026-11-10-supplier-description-design.md docs/superpowers/plans/2026-11-10-supplier-description.md
git commit -m "feat(supplier): add description field to directory API"
```

---

### Task 2: Frontend — service contract

**Files:**
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/modules/master-data/services/supplier.service.ts`
- Test: `WMS_Warehouse_Hoa_Nam_FE/src/modules/master-data/services/supplier.service.test.ts`

**Interfaces:**
- Consumes: response row có `description` (có thể null/thiếu).
- Produces: `Supplier.description: string | null`; `SupplierPayload.description?: string | null`; create/update gửi `description` đã trim hoặc `null`.

- [ ] **Step 1: Viết test service thất bại**

Thêm `description: "Linh kiện nhập khẩu"` vào fixture `row`. Cập nhật create/update test:

```ts
await createSupplier({
  name: "  Honda  ",
  status: "ACTIVE",
  description: "  Nhà cung cấp  ",
});
expect(http.post).toHaveBeenCalledWith("/mini-app/inbound/suppliers", {
  name: "Honda",
  status: "ACTIVE",
  description: "Nhà cung cấp",
});

await updateSupplier(row.id, 4, {
  name: "New name",
  status: "INACTIVE",
  description: "  ",
});
expect(http.patch).toHaveBeenCalledWith(
  "/mini-app/inbound/suppliers/supplier-uuid",
  { name: "New name", status: "INACTIVE", description: null },
  { headers: { "If-Match": '"4"' } },
);
```

Thêm test:

```ts
it("treats a missing description as null and accepts the shared max length", async () => {
  vi.mocked(http.getPage).mockResolvedValue({
    data: [{ ...row, description: undefined }],
    meta: { page: 1, per_page: 20, total: 1, last_page: 1 },
  });
  expect(
    (await listSuppliers({ status: "ACTIVE", page: 1, per_page: 20 })).rows[0]
      .description,
  ).toBeNull();
  await expect(
    updateSupplier(row.id, 4, {
      name: "New",
      status: "ACTIVE",
      description: "a".repeat(501),
    }),
  ).rejects.toThrow();
  expect(http.patch).not.toHaveBeenCalled();
});
```

- [ ] **Step 2: Chạy test để xác nhận thất bại**

```powershell
npx vitest run src/modules/master-data/services/supplier.service.test.ts
```

Expected: FAIL — `description` chưa có trong schema/payload.

- [ ] **Step 3: Cập nhật service**

```ts
export interface SupplierPayload {
  name: string;
  status: SupplierStatus;
  description?: string | null;
}

const supplierSchema = z.object({
  // ...
  description: z.string().nullable().default(null),
  // ...
});

function normalizeDescription(value: string | null | undefined): string | null {
  const trimmed = value?.trim() ?? "";
  return trimmed === "" ? null : trimmed;
}
```

Trong `createSupplier`/`updateSupplier`, gửi `description: normalizeDescription(payload.description)`. Nếu mô tả > 500 ký tự sau trim, `throw new Error("SUPPLIER_DESCRIPTION_TOO_LONG")` trước khi gọi HTTP để test chặn được.

- [ ] **Step 4: Chạy test để xác nhận pass**

```powershell
npx vitest run src/modules/master-data/services/supplier.service.test.ts
```

Expected: PASS.

- [ ] **Step 5: Commit FE service**

```powershell
git add src/modules/master-data/services/supplier.service.ts src/modules/master-data/services/supplier.service.test.ts
git commit -m "feat(supplier): add description to service contract"
```

---

### Task 3: Frontend — form, bảng, copy, CSS

**Files:**
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/modules/master-data/pages/SupplierListPage.tsx`
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/modules/master-data/pages/supplier-copy.ts`
- Modify: `WMS_Warehouse_Hoa_Nam_FE/src/modules/master-data/pages/supplier-list.css`
- Test: `WMS_Warehouse_Hoa_Nam_FE/src/modules/master-data/pages/SupplierListPage.test.tsx`

**Interfaces:**
- Consumes: `Supplier.description`, `SupplierPayload.description` từ Task 2.
- Produces: form có ô “Mô tả” (multiline 3 dòng, maxLength 500); bảng có cột “Mô tả”; text đủ vi/en/zh.

- [ ] **Step 1: Viết test page thất bại**

Thêm `description: "Linh kiện nhập khẩu"` vào fixture `row`. Cập nhật expectation create/update hiện có để có `description` (`null` khi form trống; `"Linh kiện nhập khẩu"` khi sửa NCC có sẵn mô tả).

Thêm các test:

```tsx
it("shows and saves the supplier description", async () => {
  setup();
  expect(await screen.findByText("Linh kiện nhập khẩu")).toHaveAttribute(
    "title",
    "Linh kiện nhập khẩu",
  );
  fireEvent.click(await screen.findByRole("button", { name: "Sửa NCC-001" }));
  const dialog = await screen.findByRole("dialog");
  const field = within(dialog).getByLabelText("Mô tả");
  expect(field).toHaveValue("Linh kiện nhập khẩu");
  fireEvent.change(field, { target: { value: "  Mô tả mới  " } });
  fireEvent.click(within(dialog).getByRole("button", { name: "Lưu thay đổi" }));
  await waitFor(() =>
    expect(updateSupplier).toHaveBeenCalledWith("supplier-1", 4, {
      name: "Honda",
      status: "ACTIVE",
      description: "Mô tả mới",
    }),
  );
});

it("renders a dash when a supplier has no description", async () => {
  vi.mocked(listSuppliers).mockResolvedValue({
    rows: [{ ...row, description: null }],
    page: 1, perPage: 20, total: 1, lastPage: 1,
  });
  setup();
  expect(await screen.findByText("—")).toBeInTheDocument();
});

it("rejects descriptions longer than 500 characters before saving", async () => {
  setup();
  fireEvent.click(screen.getByRole("button", { name: "Thêm NCC" }));
  const dialog = await screen.findByRole("dialog");
  fireEvent.change(within(dialog).getByLabelText(/Tên nhà cung cấp/), {
    target: { value: "A" },
  });
  fireEvent.change(within(dialog).getByLabelText("Mô tả"), {
    target: { value: "a".repeat(501) },
  });
  fireEvent.click(within(dialog).getByRole("button", { name: "Lưu NCC" }));
  expect(
    await within(dialog).findByText("Mô tả không vượt quá 500 ký tự."),
  ).toBeInTheDocument();
  expect(createSupplier).not.toHaveBeenCalled();
});
```

- [ ] **Step 2: Chạy test để xác nhận thất bại**

```powershell
npx vitest run src/modules/master-data/pages/SupplierListPage.test.tsx
```

Expected: FAIL — chưa có ô “Mô tả”/cột “Mô tả”, payload thiếu description.

- [ ] **Step 3: Thêm copy vi/en/zh**

Thêm vào cả ba object: `description`, `descriptionHelp`, `descriptionLong`, `noDescription`. Đổi `searchPlaceholder` sang tìm mã, tên hoặc mô tả.

```ts
description: "Mô tả",
descriptionHelp: "Thông tin thêm về nhà cung cấp (tối đa 500 ký tự).",
descriptionLong: "Mô tả không vượt quá 500 ký tự.",
noDescription: "—",
searchPlaceholder: "Tìm mã NCC, tên hoặc mô tả...",
```

EN: `Description`, `Additional supplier information (up to 500 characters).`, `Descriptions must not exceed 500 characters.`, `—`, `Search supplier code, name or description...`.
ZH: `描述`, `供应商的补充信息（最多500个字符）。`, `描述不能超过500个字符。`, `—`, `搜索供应商编码、名称或描述...`.

- [ ] **Step 4: Cập nhật form và bảng**

Trong `SupplierForm`:

```tsx
const [description, setDescription] = useState(supplier?.description ?? "");
const dirty =
  name !== (supplier?.name ?? "") ||
  description !== (supplier?.description ?? "") ||
  status !== (supplier?.status ?? "ACTIVE");
```

Trong `submit`, kiểm tra `[...description.trim()].length > 500` → `setErrors({ description: copy.descriptionLong })`.
Mutation payload:

```tsx
{
  name: name.trim(),
  status,
  description: description.trim() || null,
}
```

`onReload` truyền thêm `description: description.trim() || null`.
`resolveFormErrors(error, ["name", "description", "status"])`.

Thêm `UiTextField` sau ô Tên, trước Trạng thái:

```tsx
<UiTextField
  label={copy.description}
  value={description}
  fullWidth
  multiline
  rows={3}
  onChange={(event) => {
    setDescription(event.target.value);
    setErrors((previous) => ({ ...previous, description: "" }));
  }}
  error={Boolean(errors.description)}
  helperText={errors.description || copy.descriptionHelp}
  disabled={mutation.isPending}
  slotProps={{ htmlInput: { maxLength: 500 } }}
/>
```

Trong bảng: thêm `<col className="hn-ncc-col-description" />` sau cột tên, `<th>{copy.description}</th>`, và cell:

```tsx
<td>
  <span
    className={supplier.description ? "hn-ncc-description" : "hn-ncc-muted"}
    title={supplier.description ?? undefined}
  >
    {supplier.description ?? copy.noDescription}
  </span>
</td>
```

Đổi cả hai `colSpan={6}` thành `colSpan={7}`.

- [ ] **Step 5: Cập nhật CSS**

```css
.hn-ncc-table {
  min-width: 980px;
}
.hn-ncc-col-description {
  width: 27%;
}
.hn-ncc-description {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  overflow-wrap: anywhere;
}
```

Cân lại các cột để tổng = 100%: number 5, code 12, name 23, description 27, status 12, updated 11, action 10.

- [ ] **Step 6: Chạy test, typecheck, lint, format**

```powershell
npx vitest run src/modules/master-data/pages/SupplierListPage.test.tsx src/modules/master-data/services/supplier.service.test.ts
npm run typecheck
npx eslint --config eslint.config.js src/modules/master-data/pages/SupplierListPage.tsx src/modules/master-data/pages/supplier-copy.ts src/modules/master-data/services/supplier.service.ts src/modules/master-data/services/supplier.service.test.ts src/modules/master-data/pages/SupplierListPage.test.tsx
npx prettier --check src/modules/master-data/pages/SupplierListPage.tsx src/modules/master-data/pages/supplier-copy.ts src/modules/master-data/pages/supplier-list.css src/modules/master-data/services/supplier.service.ts src/modules/master-data/services/supplier.service.test.ts src/modules/master-data/pages/SupplierListPage.test.tsx
```

Expected: tất cả PASS.

- [ ] **Step 7: Commit FE UI**

```powershell
git add src/modules/master-data/pages/SupplierListPage.tsx src/modules/master-data/pages/supplier-copy.ts src/modules/master-data/pages/supplier-list.css src/modules/master-data/pages/SupplierListPage.test.tsx
git commit -m "feat(supplier): show and edit description"
```

---

### Task 4: Kiểm chứng tổng hợp và giới hạn triển khai

**Files:** không sửa thêm.

- [ ] **Step 1: Chạy lại toàn bộ test liên quan BE**

```powershell
php artisan test tests/Feature/SupplierWriteApiTest.php tests/Feature/P04InboundContractTest.php tests/Feature/SupplierBusinessCodeTest.php
```

Expected: PASS. Ghi lại số test/assert.

- [ ] **Step 2: Chạy lại FE và build**

```powershell
npx vitest run src/modules/master-data/pages/SupplierListPage.test.tsx src/modules/master-data/services/supplier.service.test.ts
npm run typecheck
npm run lint
```

Expected: PASS. Nếu `npm run lint` báo lỗi ở file không liên quan (thay đổi có sẵn của người dùng), ghi rõ file/lỗi và không sửa.

- [ ] **Step 3: Kiểm tra migration trên DB dev**

```powershell
php artisan migrate --pretend
php artisan migrate
```

Expected: chỉ chạy migration `2026_11_10_000001_add_description_to_inbound_suppliers`; cột `description` xuất hiện trong `inbound_suppliers`. Không chạy migrate production.

- [ ] **Step 4: Kiểm tra giao diện thực tế (nếu dev server chạy)**

Mở màn Danh sách NCC trên FE local; xác nhận: cột Mô tả hiển thị/“—”, form Thêm/Sửa có textarea, lưu mô tả rồi mở lại thấy dữ liệu, tìm kiếm theo mô tả trả đúng. Chụp/ghi lại màn hình và trạng thái đã kiểm. Nếu không có dev server/không đăng nhập được, ghi rõ “chưa kiểm giao diện runtime” thay vì coi test là đủ.

- [ ] **Step 5: Báo cáo**

Nêu: file đã đổi, test đã chạy + kết quả, migration đã áp dụng ở đâu, việc còn lại khi deploy production (`php artisan migrate`, deploy BE trước FE), và giới hạn kiểm chứng.
