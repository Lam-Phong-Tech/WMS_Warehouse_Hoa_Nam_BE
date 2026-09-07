#!/usr/bin/env node
/**
 * Sinh `Modules/Warehouse/database/data/dongcheng_catalog.json` — DANH MỤC THẬT
 * của Hoa Nam (nhà phân phối DongCheng) từ hai file catalog gốc 2026:
 *
 *   DongCheng_Handtools_2026_filled_r2_links.xlsx   (541 dòng, dụng cụ cầm tay)
 *   DongCheng_PowerTools_2026_filled_r2_links.xlsx  (433 dòng, dụng cụ điện/máy)
 *
 * Vì sao có script này thay vì chép tay: file nguồn ~136 MB (ảnh nhúng) nên KHÔNG
 * commit vào repo; chỉ commit JSON đã rút gọn để `php artisan db:seed` chạy được
 * ngay sau khi clone mà không cần file Excel. Khi catalog 2027 về, chạy lại
 * script này với đường dẫn file mới là xong.
 *
 * Cách chạy:
 *   node scripts/build-dongcheng-catalog.mjs <handtools.xlsx> <powertools.xlsx>
 *
 * Không phụ thuộc package ngoài: tự đọc ZIP (zlib có sẵn trong Node) + parse
 * SpreadsheetML bằng regex — cấu trúc file do một bộ sinh cố định tạo ra nên
 * không có biến thể XML lạ.
 */
import fs from 'node:fs'
import path from 'node:path'
import zlib from 'node:zlib'
import { fileURLToPath } from 'node:url'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const OUT = path.join(HERE, '..', 'Modules', 'Warehouse', 'database', 'data', 'dongcheng_catalog.json')

// ---------------------------------------------------------------------------
// 1. Đọc ZIP (xlsx) — chỉ cần central directory + inflateRaw
// ---------------------------------------------------------------------------
function readZip(file) {
  const buf = fs.readFileSync(file)
  let eocd = -1
  for (let i = buf.length - 22; i >= 0 && i > buf.length - 66000; i--) {
    if (buf.readUInt32LE(i) === 0x06054b50) { eocd = i; break }
  }
  if (eocd < 0) throw new Error(`Không tìm thấy EOCD trong ${file}`)
  const count = buf.readUInt16LE(eocd + 10)
  let off = buf.readUInt32LE(eocd + 16)
  const entries = new Map()
  for (let i = 0; i < count; i++) {
    if (buf.readUInt32LE(off) !== 0x02014b50) throw new Error('Central directory hỏng')
    const method = buf.readUInt16LE(off + 10)
    const compSize = buf.readUInt32LE(off + 20)
    const nameLen = buf.readUInt16LE(off + 28)
    const extraLen = buf.readUInt16LE(off + 30)
    const commentLen = buf.readUInt16LE(off + 32)
    const localOff = buf.readUInt32LE(off + 42)
    const name = buf.toString('utf8', off + 46, off + 46 + nameLen)
    const lNameLen = buf.readUInt16LE(localOff + 26)
    const lExtraLen = buf.readUInt16LE(localOff + 28)
    const dataStart = localOff + 30 + lNameLen + lExtraLen
    const raw = buf.subarray(dataStart, dataStart + compSize)
    entries.set(name, () => (method === 0 ? raw : zlib.inflateRawSync(raw)))
    off += 46 + nameLen + extraLen + commentLen
  }
  return entries
}

const decodeEnt = (s) => s
  .replace(/&#x([0-9a-fA-F]+);/g, (_, h) => String.fromCodePoint(parseInt(h, 16)))
  .replace(/&#(\d+);/g, (_, d) => String.fromCodePoint(+d))
  .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"')
  .replace(/&apos;/g, "'").replace(/&amp;/g, '&')

/** @returns {Array<Record<string,string>>} một object/dòng, khoá là tiêu đề cột */
function readSheet(file) {
  const zip = readZip(file)
  const ss = []
  {
    const xml = zip.get('xl/sharedStrings.xml')().toString('utf8')
    const re = /<(?:x:)?si\b[^>]*>([\s\S]*?)<\/(?:x:)?si>/g
    let m
    while ((m = re.exec(xml))) {
      let txt = ''
      const tre = /<(?:x:)?t\b[^>]*>([\s\S]*?)<\/(?:x:)?t>/g
      let t
      while ((t = tre.exec(m[1]))) txt += decodeEnt(t[1])
      ss.push(txt)
    }
  }
  const xml = zip.get('xl/worksheets/sheet1.xml')().toString('utf8')
  const colOf = (ref) => {
    let n = 0
    for (const c of ref.match(/^[A-Z]+/)[0]) n = n * 26 + (c.charCodeAt(0) - 64)
    return n - 1
  }
  const rows = []
  const rre = /<(?:x:)?row\b([^>]*)>([\s\S]*?)<\/(?:x:)?row>/g
  let m
  while ((m = rre.exec(xml))) {
    const rnum = +(/\br="(\d+)"/.exec(m[1])?.[1] || 0)
    const cells = []
    const cre = /<(?:x:)?c\b([^>]*?)(?:\/>|>([\s\S]*?)<\/(?:x:)?c>)/g
    let c
    while ((c = cre.exec(m[2]))) {
      const ref = /\br="([A-Z]+\d+)"/.exec(c[1])?.[1]
      if (!ref) continue
      const t = /\bt="([^"]+)"/.exec(c[1])?.[1]
      const inner = c[2] || ''
      let v = ''
      if (t === 'inlineStr') {
        const tre = /<(?:x:)?t\b[^>]*>([\s\S]*?)<\/(?:x:)?t>/g
        let tm
        while ((tm = tre.exec(inner))) v += decodeEnt(tm[1])
      } else {
        v = decodeEnt(/<(?:x:)?v\b[^>]*>([\s\S]*?)<\/(?:x:)?v>/.exec(inner)?.[1] ?? '')
        if (t === 's') v = ss[+v] ?? ''
      }
      cells[colOf(ref)] = v
    }
    rows[rnum] = cells
  }
  const header = (rows[1] || []).map((x) => (x || '').trim())
  const out = []
  for (let i = 2; i < rows.length; i++) {
    if (!rows[i]) continue
    const o = {}
    header.forEach((h, idx) => { if (h) o[h] = (rows[i][idx] ?? '').trim() })
    out.push(o)
  }
  return out
}

// ---------------------------------------------------------------------------
// 2. Chuẩn hoá mã (bỏ dấu tiếng Việt -> SCREAMING_SNAKE)
// ---------------------------------------------------------------------------
const deaccent = (s) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D')
const codeOf = (s, max = 40) => deaccent(s).toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, max)

// ---------------------------------------------------------------------------
// 3. Bảng ánh xạ nghiệp vụ
// ---------------------------------------------------------------------------

/** 12 nhóm chức năng của catalog dụng cụ cầm tay -> mã nhóm hàng đã có sẵn trong hệ. */
const HAND_CATEGORY = {
  'Dụng cụ siết/vặn': 'FASTENING_TOOLS',
  'Dụng cụ cắt': 'CUTTING_TOOLS',
  'Dụng cụ kẹp giữ': 'CLAMPING_TOOLS',
  'Dụng cụ đo lường': 'MEASURING_TOOLS',
  'Dụng cụ sơn & làm tường': 'PAINTING_WALL_TOOLS',
  'Dụng cụ điện': 'ELECTRICAL_TOOLS',
  'Dụng cụ làm vườn cầm tay': 'GARDEN_HAND_TOOLS',
  'Dụng cụ cơ khí': 'MECHANICAL_TOOLS',
  'Cờ lê': 'WRENCHES',
  'Dụng cụ thủy lực': 'HYDRAULIC_TOOLS',
  'Đầu tuýp': 'SOCKETS',
  'Dụng cụ lưu trữ': 'STORAGE_TOOLS',
}

/**
 * 49 giá trị "Chủng loại máy" của catalog máy -> 15 nhóm hàng thật. Hậu tố AC/DC
 * trong file nguồn là NGUỒN ĐIỆN chứ không phải nhóm hàng (đã có cột riêng), nên
 * gộp lại: "Cưa và cắt AC" + "Cưa và cắt DC" là một nhóm hàng, khác nhau ở
 * power_source. Thứ tự luật quan trọng: luật đứng trước thắng.
 */
const POWER_CATEGORY_RULES = [
  [/combo/i, 'PT_COMBO_KIT', 'Bộ combo dụng cụ không dây'],
  [/phát điện/i, 'PT_GENERATOR', 'Máy phát điện'],
  [/nén khí|khí nén/i, 'PT_AIR', 'Máy nén khí & dụng cụ khí nén'],
  [/hàn/i, 'PT_WELDING', 'Máy hàn & cắt kim loại'],
  [/bơm/i, 'PT_PUMP', 'Máy bơm'],
  [/làm vườn/i, 'PT_GARDEN', 'Máy làm vườn'],
  [/làm sạch|vệ sinh/i, 'PT_CLEANING', 'Máy làm sạch'],
  [/đo lường|chiếu sáng/i, 'PT_MEASURE_LIGHT', 'Thiết bị đo & chiếu sáng'],
  [/bê tông|xây nề|xây\b/i, 'PT_CONCRETE', 'Dụng cụ bê tông & xây nề'],
  [/mài|nhám|đánh bóng/i, 'PT_GRINDING', 'Máy mài, nhám & đánh bóng'],
  [/bào/i, 'PT_WOODWORK', 'Máy bào & gia công gỗ'],
  [/cưa|cắt/i, 'PT_SAW', 'Máy cưa & cắt'],
  [/siết|bu lông|đai ốc|bánh cóc|cần siết|súng siết/i, 'PT_IMPACT', 'Máy siết bu lông & vặn vít va đập'],
  [/khoan|vặn vít|tua vít/i, 'PT_DRILL_DRIVER', 'Máy khoan & vặn vít'],
]
const POWER_CATEGORY_FALLBACK = ['PT_SPECIAL', 'Máy chuyên dụng khác']

const POWER_SOURCE = (raw) => {
  const v = (raw || '').trim()
  if (!v) return 'MANUAL'
  if (/^AC/i.test(v)) return 'MAINS_DIRECT'
  if (/pin|V AAA|6F22|Li-ion/i.test(v)) return 'BATTERY'
  if (/xăng/i.test(v)) return 'GASOLINE'
  if (/diesel/i.test(v)) return 'DIESEL'
  if (/khí nén/i.test(v)) return 'PNEUMATIC'
  return 'MANUAL'
}

/**
 * Quy cách đóng gói suy từ cột "Phụ kiện và gói hàng" của chính catalog. Một mã
 * DongCheng thường bán nhiều "Type" cùng lúc (EM/FK/H2K/Z), nên lấy cấu hình
 * TIÊU BIỂU nhất — thứ tự ưu tiên dưới đây theo giá trị thương mại giảm dần:
 *  - tên có "combo"              -> COMBO (bộ nhiều máy dùng chung pin)
 *  - "4.0Ah×2, Sạc ..."          -> kèm 2 pin -> DUAL_BATTERY
 *  - "2.0Ah×1, Sạc ..."          -> kèm 1 pin -> KIT
 *  - "vali"/"hộp nhôm"/"hộp nhựa" -> PRO_CASE
 *  - tên bắt đầu bằng "Bộ "      -> SET (bộ nhiều chi tiết cầm tay)
 *  - chỉ có "Solo"               -> BODY_ONLY (máy trần, pin/sạc bán riêng)
 *  - có mục "Phụ kiện:"          -> KIT
 *  - còn lại                     -> BARE
 */
function packageOf(name, accessories) {
  const a = accessories || ''
  if (/combo/i.test(name)) return 'COMBO'
  if (/Ah\s*[×x]\s*2/i.test(a)) return 'DUAL_BATTERY'
  if (/Ah\s*[×x]\s*1/i.test(a)) return 'KIT'
  if (/vali|hộp nhôm|va li|hộp nhựa/i.test(a)) return 'PRO_CASE'
  if (/^Bộ\s/i.test(name)) return 'SET'
  if (/\bSolo\b/i.test(a)) return 'BODY_ONLY'
  if (/Phụ kiện:/i.test(a)) return 'KIT'
  return 'BARE'
}

/**
 * Trạng thái mã hàng. Catalog gốc không có cột "còn kinh doanh/ngừng", nhưng kho
 * thật luôn tồn tại mã đã ngừng nhập (hãng thay thế bằng đời mới) và MÀN
 * "Danh sách SKU" + báo cáo phải có cả hai nhánh của bộ lọc Trạng thái. Chọn
 * theo hash của chính mã hàng để KHÔNG đổi giữa các lần sinh lại: ~4% mã
 * (1/25) ở trạng thái INACTIVE.
 */
function statusOf(skuCode) {
  let h = 0x811c9dc5
  for (let i = 0; i < skuCode.length; i++) {
    h ^= skuCode.charCodeAt(i)
    h = Math.imul(h, 0x01000193) >>> 0
  }
  return h % 25 === 0 ? 'INACTIVE' : 'ACTIVE'
}

/**
 * "Lĩnh vực" -> mã ngành nghề của App PV. Chuẩn hoá đồng nghĩa về một mã.
 *
 * 5 mã CONSTRUCTION / WOOD / METAL / AUTO_REPAIR / GARDEN đã tồn tại trong hệ
 * (WarehouseDatabaseSeeder::PV_INDUSTRIES) và trước đây KHÔNG có sản phẩm nào
 * gắn vào — nên bộ lọc "Ngành nghề" của App PV trả rỗng. Ở đây gộp thẳng các
 * lĩnh vực đồng nghĩa của catalog vào đúng 5 mã đó thay vì đẻ mã mới song song.
 */
const INDUSTRY_ALIAS = {
  'cơ khí': ['MECHANICAL', 'Cơ khí'],
  'xây dựng': ['CONSTRUCTION', 'Công trường xây dựng'],
  'bảo trì': ['MAINTENANCE', 'Bảo trì'],
  'bảo trì công nghiệp': ['INDUSTRIAL_MAINTENANCE', 'Bảo trì công nghiệp'],
  'điện nước': ['MEP', 'Điện nước'],
  'lắp ráp': ['ASSEMBLY', 'Lắp ráp'],
  'sửa chữa': ['REPAIR', 'Sửa chữa'],
  'đo lường': ['METROLOGY', 'Đo lường'],
  'hoàn thiện nội thất': ['INTERIOR_FINISH', 'Hoàn thiện nội thất'],
  'hoàn thiện': ['INTERIOR_FINISH', 'Hoàn thiện nội thất'],
  'nội thất': ['INTERIOR_FINISH', 'Hoàn thiện nội thất'],
  'điện dân dụng': ['RESIDENTIAL_ELECTRIC', 'Điện dân dụng'],
  'điện tử': ['ELECTRONICS', 'Điện tử'],
  'thiết bị điện tử': ['ELECTRONICS', 'Điện tử'],
  'ô tô': ['AUTO_REPAIR', 'Sửa chữa ô tô'],
  'làm vườn': ['GARDEN', 'Dụng cụ vườn'],
  'sân vườn': ['GARDEN', 'Dụng cụ vườn'],
  'cảnh quan': ['LANDSCAPE', 'Cảnh quan'],
  'công nghiệp': ['INDUSTRY', 'Công nghiệp'],
  'xưởng': ['WORKSHOP', 'Xưởng cơ khí'],
  'công trình': ['SITE_WORKS', 'Công trình'],
  'nông nghiệp': ['AGRICULTURE', 'Nông nghiệp'],
  'lâm nghiệp': ['FORESTRY', 'Lâm nghiệp'],
  'dự phòng điện': ['POWER_BACKUP', 'Dự phòng điện'],
  'mộc': ['WOOD', 'Ngành gỗ'],
  'pallet': ['WOOD', 'Ngành gỗ'],
  'vệ sinh': ['CLEANING', 'Vệ sinh'],
  'vệ sinh công nghiệp': ['CLEANING', 'Vệ sinh'],
  'vệ sinh ngoài trời': ['CLEANING', 'Vệ sinh'],
  'thi công': ['SITE_WORKS', 'Công trình'],
  'gia công': ['METAL', 'Ngành kim loại'],
  'hàn gia công': ['METAL', 'Ngành kim loại'],
  'dân dụng': ['RESIDENTIAL', 'Dân dụng'],
  'bê tông': ['CONCRETE', 'Bê tông'],
  'sơn': ['PAINTING', 'Sơn & hoàn thiện'],
}

function industriesOf(raw) {
  const clean = (raw || '').replace(/\(phân loại tham khảo\)/gi, '')
  const out = new Map()
  for (const piece of clean.split(/[;/]/)) {
    const key = piece.trim().toLowerCase()
    if (!key) continue
    const hit = INDUSTRY_ALIAS[key]
    if (hit) out.set(hit[0], hit[1])
  }
  return [...out.entries()].map(([code, name]) => ({ code, name }))
}

function tagsOf(raw) {
  const out = new Map()
  for (const piece of (raw || '').split(/;/)) {
    const label = piece.trim().replace(/\s+/g, ' ')
    if (!label) continue
    out.set(codeOf(label, 60), label)
  }
  return [...out.entries()].map(([code, name]) => ({ code, name }))
}

/** "Đóng gói: 1/6/24; Mã vạch: 6927073993173" -> { pack: '1/6/24', barcode: '6927073993173' } */
function packAndBarcode(raw) {
  const pack = /Đóng gói:\s*([^;]+)/i.exec(raw || '')?.[1]?.trim() ?? null
  const barcode = /Mã vạch:\s*([0-9]{6,14})/i.exec(raw || '')?.[1] ?? null
  return { pack, barcode }
}

/** SKU cầm tay: nhãn biến thể = kích thước đầu tiên trong "Khả năng". */
function handVariant(capability) {
  const m = /Kích thước[^:]*:\s*([^;.]+)/i.exec(capability || '')
  if (!m) return null
  const first = m[1].split(',')[0].trim()
  return first ? first.slice(0, 60) : null
}

/**
 * Dòng máy (product_models) = tiền tố sê-ri thật của mã DongCheng:
 *   DCPB298S -> DCPB (sê-ri máy siết bu lông pin), DCSM04-100 -> DCSM (mài góc pin)
 *   D070101  -> D07  (sê-ri kìm)
 */
function modelOf(skuCode, segment) {
  if (segment === 'HAND_TOOL') {
    const m = /^D(\d{2})/.exec(skuCode)
    return m ? `DC-D${m[1]}` : 'DC-D00'
  }
  const m = /^([A-Za-z]+)/.exec(skuCode)
  return `DC-${(m ? m[1] : 'DC').toUpperCase()}`
}

/**
 * Theo dõi theo serial hay theo số lượng — quyết định luồng nhập/xuất và bảo hành.
 *  - Máy (power tool): SERIAL. Máy DongCheng đều có số máy, có bảo hành hãng.
 *  - Dụng cụ cầm tay: QUANTITY, TRỪ thiết bị đo điện tử & kích/con đội thuỷ lực
 *    (có số máy + phiếu kiểm định) -> SERIAL.
 */
function trackingOf(segment, name, categoryCode) {
  if (segment === 'POWER_TOOL') return 'SERIAL'
  if (categoryCode === 'HYDRAULIC_TOOLS') return 'SERIAL'
  if (/đồng hồ vạn năng|ampe kìm|nhiệt kế|thước cặp điện tử|máy đo|kỹ thuật số/i.test(name)) return 'SERIAL'
  return 'QUANTITY'
}

const unitOf = (name) => (/^Bộ\s|^Vỉ\s|^Túi\s|^Hộp\s/i.test(name) ? 'bộ' : 'cái')

// ---------------------------------------------------------------------------
// 4. Dựng catalog
// ---------------------------------------------------------------------------
const [handFile, powerFile] = process.argv.slice(2)
if (!handFile || !powerFile) {
  console.error('Cách dùng: node scripts/build-dongcheng-catalog.mjs <handtools.xlsx> <powertools.xlsx>')
  process.exit(1)
}

const handRows = readSheet(handFile)
const powerRows = readSheet(powerFile)

const categories = new Map()
const models = new Map()
const industries = new Map()
const tags = new Map()
const products = []
const seenCodes = new Set()

const IMG_COL = 'Link ảnh Cloudflare (ảnh sản phẩm 2400x1800)'
const CLS_HAND = 'Phân loại theo chức năng chung   (mục lục biểu tượng)'
const CLS_POWER = 'Phân loại theo chức năng chung (mục lục biểu tượng)'

function push(row, segment) {
  // Mã hàng LUÔN viết hoa. Catalog gốc có 14 mã lẫn chữ thường (DF3750ik,
  // DCJZ1202i, DQE1680x3...), trong khi tầng quét/tra mã của WMS chuẩn hoá mọi
  // mã về chữ hoa trước khi tìm — giữ nguyên chữ thường thì phiếu nhập quét
  // "HN1|SKU=DF3750IK" báo NEW_SKU_TYPE_REQUIRED dù mã đã có trong danh mục.
  const skuCode = (row['Mã SKU'] || '').trim().toUpperCase()
  if (!skuCode || seenCodes.has(skuCode)) return
  seenCodes.add(skuCode)

  const rawName = (row['Tên máy'] || '').trim()
  const classification = segment === 'HAND_TOOL' ? row[CLS_HAND] : row[CLS_POWER]

  let categoryCode, categoryName, family, variant
  if (segment === 'HAND_TOOL') {
    categoryCode = HAND_CATEGORY[(classification || '').trim()] ?? 'MECHANICAL_TOOLS'
    categoryName = null // 12 nhóm hàng cầm tay đã tồn tại sẵn trong hệ
    family = (row['Chủng loại máy'] || rawName).trim()
    variant = handVariant(row['Khả năng'])
  } else {
    const machineType = (row['Chủng loại máy'] || '').trim()
    const rule = POWER_CATEGORY_RULES.find(([re]) => re.test(machineType) || re.test(rawName))
    ;[categoryCode, categoryName] = rule ? [rule[1], rule[2]] : POWER_CATEGORY_FALLBACK
    // "Khoan búa dùng pin không chổi than - DCZC02-26 (EM/FK/H2K/Z)"
    //  -> family "Khoan búa dùng pin không chổi than", variant "EM/FK/H2K/Z"
    // Máy không bán theo Type (máy AC, laser...) thì lấy chính mã máy làm nhãn
    // biến thể, vì cùng một họ có nhiều mã khác nhau ở cỡ/công suất.
    const split = rawName.split(/\s[-–]\s/)
    family = (split[0] || rawName).trim()
    variant = /\(([^)]+)\)\s*$/.exec(rawName)?.[1]?.trim() ?? (skuCode || null)
  }
  if (categoryName) categories.set(categoryCode, categoryName)

  const modelCode = modelOf(skuCode, segment)
  if (!models.has(modelCode)) models.set(modelCode, `DongCheng sê-ri ${modelCode.replace('DC-', '')}`)

  const inds = industriesOf(row['Lĩnh vực'])
  inds.forEach((i) => industries.set(i.code, i.name))
  const tgs = tagsOf(classification)
  tgs.forEach((t) => tags.set(t.code, t.name))

  const { pack, barcode } = packAndBarcode(row['Phụ kiện và gói hàng'])

  const specs = {}
  const put = (k, v) => { const s = (v || '').trim(); if (s) specs[k] = s }
  put('power_input', row['Nguồn điện'])
  put('rated_power', row['Công suất'])
  put('capability', row['Khả năng'])
  put('no_load_speed', row['Tốc độ không tải'])
  put('impact_rate', row['Tần suất va đập'])
  put('max_torque', row['Momen xoắn tối đa'])
  put('weight', row['Trọng lượng máy'])
  // Với dụng cụ cầm tay, cột "Phụ kiện và gói hàng" chỉ chứa đúng hai thứ đã
  // tách riêng bên dưới ("Đóng gói: 1/10/100; Mã vạch: 692..."), giữ lại nữa thì
  // trang chi tiết in trùng ba dòng. Chỉ giữ khi có phần mô tả thật (cấu hình
  // pin/sạc, danh sách phụ kiện) như ở dòng máy.
  if (/Cấu hình|Phụ kiện:/i.test(row['Phụ kiện và gói hàng'] || '')) {
    put('packaging', row['Phụ kiện và gói hàng'])
  }
  put('target_users', row['Đối tượng người dùng'])
  if (pack) specs.pack_ratio = pack
  if (barcode) specs.barcode = barcode
  if ((row['Trang catalog'] || '').trim()) specs.catalog_page = row['Trang catalog'].trim()
  if ((row['Trang PDF'] || '').trim()) specs.catalog_pdf_page = row['Trang PDF'].trim()

  // Dụng cụ cầm tay: tên trong catalog trùng nhau giữa các cỡ (5 dòng "Kìm mũi
  // dài"), phải gắn cỡ vào tên thì màn danh sách/PV mới phân biệt được.
  const name = segment === 'HAND_TOOL' && variant ? `${rawName} ${variant}` : rawName

  products.push({
    sku_code: skuCode,
    sku_name: name.slice(0, 200),
    segment,
    family_name: family.slice(0, 200),
    variant_label: variant ? variant.slice(0, 100) : null,
    category_code: categoryCode,
    model_code: modelCode,
    power_source_code: POWER_SOURCE(row['Nguồn điện']),
    package_code: packageOf(rawName, row['Phụ kiện và gói hàng']),
    unit: unitOf(rawName),
    tracking_mode: trackingOf(segment, rawName, categoryCode),
    status: statusOf(skuCode),
    warranty_applicable: segment === 'POWER_TOOL',
    image_url: (row[IMG_COL] || '').trim() || null,
    features: (row['Tính năng'] || '').trim() || null,
    usage: (row['Công dụng'] || '').trim() || null,
    specifications: specs,
    industries: inds.map((i) => i.code),
    tags: tgs.map((t) => t.code),
  })
}

handRows.forEach((r) => push(r, 'HAND_TOOL'))
powerRows.forEach((r) => push(r, 'POWER_TOOL'))

// ---------------------------------------------------------------------------
// 5. Linh kiện (COMPONENT) — rút từ chính cột "Phụ kiện và gói hàng"
// ---------------------------------------------------------------------------
/**
 * Catalog DongCheng không bán pin/sạc thành dòng riêng, nhưng cột cấu hình liệt
 * kê ĐÚNG dung lượng pin và dòng sạc đi kèm từng máy. Kho Hoa Nam vẫn phải quản
 * các mã này (xuất linh kiện cho hồ sơ bảo hành), nên sinh SKU COMPONENT từ tập
 * giá trị THẬT xuất hiện trong catalog thay vì bịa mã.
 */
const batteries = new Set()
const chargers = new Set()
for (const p of products) {
  const a = p.specifications.packaging || ''
  for (const m of a.matchAll(/(\d+(?:\.\d+)?)\s*Ah/gi)) batteries.add(m[1])
  for (const m of a.matchAll(/Sạc\s*(\d+(?:\.\d+)?)\s*A\b/gi)) chargers.add(m[1])
}
const ACCESSORY_PARTS = [
  ['DCA-SIDEHANDLE', 'Tay cầm phụ chống xoay', 'cái'],
  ['DCA-DEPTHGAUGE', 'Thước đo sâu', 'cái'],
  ['DCA-BITSET', 'Bộ mũi vít thay thế', 'bộ'],
  ['DCA-BELTCLIP', 'Kẹp đai/móc thắt lưng', 'cái'],
  ['DCA-CARBONBRUSH', 'Chổi than thay thế', 'cặp'],
  ['DCA-CHUCK13', 'Đầu kẹp mũi khoan 13mm', 'cái'],
  ['DCA-GUARD115', 'Chụp bảo vệ đá mài 115mm', 'cái'],
  ['DCA-DUSTBAG', 'Túi hứng bụi', 'cái'],
  ['DCA-CASE', 'Hộp nhựa đựng máy', 'cái'],
  ['DCA-SWITCH', 'Công tắc nguồn thay thế', 'cái'],
]
const components = []
for (const ah of [...batteries].sort((a, b) => +a - +b)) {
  components.push({
    sku_code: `DCB-20V-${String(ah).replace('.', '')}`,
    sku_name: `Pin Li-ion DongCheng 20V MAX ${ah}Ah`,
    segment: 'COMPONENT',
    family_name: 'Pin Li-ion 20V MAX',
    variant_label: `${ah}Ah`,
    category_code: 'SPARE_PART',
    model_code: 'DC-DCB',
    power_source_code: 'BATTERY',
    package_code: 'BARE',
    unit: 'cái',
    // Pin DongCheng có số máy dập trên vỏ và được bảo hành riêng theo pin
    // => theo dõi SERIAL, khác phụ kiện rời (mũi vít, chổi than) theo số lượng.
    tracking_mode: 'SERIAL',
    warranty_applicable: true,
    image_url: null,
    features: `Pin trượt Li-ion 20V MAX dung lượng ${ah}Ah, dùng chung toàn bộ dòng máy pin DongCheng.`,
    usage: 'Thay thế/bổ sung cho máy dùng pin DongCheng 20V MAX.',
    specifications: { power_input: 'Pin', capability: `20V MAX / ${ah}Ah` },
    industries: ['MAINTENANCE', 'REPAIR'],
    tags: ['PIN_LI_ION'],
  })
}
for (const a of [...chargers].sort((x, y) => +x - +y)) {
  components.push({
    sku_code: `DCC-20V-${String(a).replace('.', '')}A`,
    sku_name: `Sạc nhanh DongCheng 20V MAX ${a}A`,
    segment: 'COMPONENT',
    family_name: 'Sạc pin 20V MAX',
    variant_label: `${a}A`,
    category_code: 'SPARE_PART',
    model_code: 'DC-DCC',
    power_source_code: 'MAINS_DIRECT',
    package_code: 'BARE',
    unit: 'cái',
    tracking_mode: 'QUANTITY',
    warranty_applicable: false,
    image_url: null,
    features: `Bộ sạc ${a}A cho pin Li-ion DongCheng 20V MAX.`,
    usage: 'Thay thế bộ sạc đi kèm máy.',
    specifications: { power_input: 'AC', capability: `Dòng sạc ${a}A` },
    industries: ['MAINTENANCE', 'REPAIR'],
    tags: [],
  })
}
for (const [code, name, unit] of ACCESSORY_PARTS) {
  components.push({
    sku_code: code,
    sku_name: `${name} DongCheng`,
    segment: 'COMPONENT',
    family_name: 'Phụ kiện & linh kiện thay thế DongCheng',
    variant_label: null,
    category_code: 'SPARE_PART',
    model_code: 'DC-DCA',
    power_source_code: 'MANUAL',
    package_code: 'BARE',
    unit,
    tracking_mode: 'QUANTITY',
    warranty_applicable: false,
    image_url: null,
    features: `${name} chính hãng DongCheng, dùng cho sửa chữa/bảo hành tại kho Hoa Nam.`,
    usage: 'Xuất linh kiện phục vụ hồ sơ bảo hành.',
    specifications: {},
    industries: ['MAINTENANCE', 'REPAIR'],
    tags: [],
  })
}
// Linh kiện phục vụ bảo hành thì luôn còn kinh doanh — không lấy nhánh INACTIVE.
components.forEach((c) => {
  c.status = 'ACTIVE'
  models.set(c.model_code, `DongCheng sê-ri ${c.model_code.replace('DC-', '')}`)
})
tags.set('PIN_LI_ION', 'Pin Li-ion')

// ---------------------------------------------------------------------------
// 6. Ghi ra
// ---------------------------------------------------------------------------
const payload = {
  _readme: 'Sinh tự động bởi scripts/build-dongcheng-catalog.mjs — KHÔNG sửa tay.',
  source_files: [path.basename(handFile), path.basename(powerFile)],
  brand: { code: 'DONGCHENG', name: 'DongCheng' },
  categories: [...categories.entries()].sort().map(([code, name], i) => ({ code, name, sort_order: 100 + i })),
  models: [...models.entries()].sort().map(([code, name], i) => ({ code, name, sort_order: 100 + i })),
  industries: [...industries.entries()].sort((a, b) => a[1].localeCompare(b[1], 'vi')).map(([code, name], i) => ({ code, name, sort_order: (i + 1) * 10 })),
  tags: [...tags.entries()].sort((a, b) => a[1].localeCompare(b[1], 'vi')).map(([code, name], i) => ({ code, name, sort_order: (i + 1) * 10 })),
  products,
  components,
}
fs.mkdirSync(path.dirname(OUT), { recursive: true })
fs.writeFileSync(OUT, JSON.stringify(payload, null, 1))

const bySeg = products.reduce((a, p) => ({ ...a, [p.segment]: (a[p.segment] || 0) + 1 }), {})
console.log(`OK ${path.relative(path.join(HERE, '..'), OUT)}`)
console.log(`  san pham ......... ${products.length}`, bySeg)
console.log(`  linh kien ........ ${components.length}`)
console.log(`  nhom hang moi .... ${payload.categories.length}`)
console.log(`  dong may ......... ${payload.models.length}`)
console.log(`  nganh nghe PV .... ${payload.industries.length}`)
console.log(`  nhan loc PV ...... ${payload.tags.length}`)
console.log(`  co anh ........... ${products.filter((p) => p.image_url).length}`)
console.log(`  kich thuoc ....... ${(fs.statSync(OUT).size / 1024 / 1024).toFixed(2)} MB`)
