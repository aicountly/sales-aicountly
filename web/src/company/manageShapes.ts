/**
 * Normalisers for the Manage API payloads relayed through `/api/manage/*`.
 *
 * Manage owns companies, branches and financial years. Its list endpoints have
 * grown several envelope shapes over the years (`{data: [...]}`, `{data: {companies}}`,
 * a bare array), and the row fields differ between `companies` (comp_id /
 * company_name / ownership) and `companyinfo` (cmp_id / comp_name / fy_list /
 * branch_list). Everything below is pure so it can be unit-tested.
 */

export interface CompanyOption {
  cmpId: number
  name: string
  shortName: string | null
  /** `owner` when the signed-in user created the company, `shared` when delegated. */
  ownership: 'owner' | 'shared' | null
  /** Portal access type derived from the row: 1 owner, 0 delegated, null unknown. */
  acsType: 0 | 1 | null
  status: number | null
}

export interface FyOption {
  fyId: number
  /** YYYY-MM-DD, '' when unknown. */
  start: string
  end: string
  label: string
  defaultValuationMethod: string | null
}

export interface BranchOption {
  boId: number
  name: string
  isHeadOffice: boolean
}

export interface CompanyInfo {
  cmpId: number | null
  name: string
  fyList: FyOption[]
  branches: BranchOption[]
  hoId: number | null
  /** Registered office, one letterhead line per entry. Empty when Manage has none. */
  addressLines: string[]
  /** GSTIN as Manage holds it, '' when unregistered or unknown. */
  gstin: string
}

type Row = Record<string, unknown>

function isRow(v: unknown): v is Row {
  return !!v && typeof v === 'object' && !Array.isArray(v)
}

function rowsOf(v: unknown): Row[] {
  return Array.isArray(v) ? v.filter(isRow) : []
}

function num(v: unknown): number | null {
  if (v === null || v === undefined || v === '') return null
  const n = typeof v === 'number' ? v : Number(String(v).trim())
  return Number.isFinite(n) ? n : null
}

function str(v: unknown): string {
  return v === null || v === undefined ? '' : String(v).trim()
}

function truthy(v: unknown): boolean {
  if (v === true || v === 1) return true
  if (typeof v === 'string') return ['1', 'true', 'yes'].includes(v.trim().toLowerCase())
  return false
}

/** Parse a date-ish value to YYYY-MM-DD without a timezone shift. */
export function toIsoDate(raw: unknown): string {
  const s = str(raw)
  if (!s) return ''
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (m) return `${m[1]}-${m[2]}-${m[3]}`
  const t = Date.parse(s)
  if (!Number.isFinite(t)) return ''
  return new Date(t).toISOString().slice(0, 10)
}

/** `FY 2025-26` from a start and end date; falls back to the given label or id. */
export function formatFyLabel(start: string, end: string, fallback = ''): string {
  const s = toIsoDate(start)
  const e = toIsoDate(end)
  if (s && e) {
    const sy = s.slice(0, 4)
    const ey = e.slice(0, 4)
    return sy === ey ? `FY ${sy}` : `FY ${sy}-${ey.slice(-2)}`
  }
  return fallback
}

/**
 * Mirrors the API's ManageCompanyAccessMapper: acs_type wins, then the
 * ownership label, then is_creator.
 */
export function resolveAcsType(row: Row): 0 | 1 | null {
  const acs = num(row.acs_type)
  if (acs === 1) return 1
  if (acs === 0) return 0
  const ownership = str(row.ownership).toLowerCase()
  if (ownership === 'owner') return 1
  if (['shared', 'delegated', 'user', 'viewer', 'editor', 'member'].includes(ownership)) return 0
  if (truthy(row.is_creator)) return 1
  return null
}

/** Rows out of any `/manage/companies` envelope. */
export function extractCompanyRows(body: unknown): Row[] {
  if (Array.isArray(body)) return rowsOf(body)
  if (!isRow(body)) return []
  const data = body.data
  if (Array.isArray(data)) return rowsOf(data)
  if (isRow(data)) {
    if (Array.isArray(data.companies)) return rowsOf(data.companies)
    if (Array.isArray(data.items)) return rowsOf(data.items)
    if (data.comp_id != null || data.cmp_id != null || data.id != null) return [data]
  }
  if (Array.isArray(body.companies)) return rowsOf(body.companies)
  if (Array.isArray(body.items)) return rowsOf(body.items)
  return []
}

export function parseCompanyRow(row: Row): CompanyOption | null {
  const cmpId = num(row.comp_id) ?? num(row.cmp_id) ?? num(row.id)
  if (cmpId === null || cmpId <= 0) return null
  const name = str(row.company_name) || str(row.comp_name) || str(row.cmp_name) || str(row.name) || `Company #${cmpId}`
  const ownership = str(row.ownership).toLowerCase()
  return {
    cmpId,
    name,
    shortName: str(row.comp_short_name) || str(row.cmp_short_name) || str(row.short_name) || null,
    ownership: ownership === 'owner' ? 'owner' : ownership === 'shared' ? 'shared' : null,
    acsType: resolveAcsType(row),
    status: num(row.comp_status) ?? num(row.cmp_status) ?? num(row.status),
  }
}

/** Companies the user can open, de-duplicated by id in payload order. */
export function parseCompanyList(body: unknown): CompanyOption[] {
  const seen = new Set<number>()
  const out: CompanyOption[] = []
  for (const row of extractCompanyRows(body)) {
    const c = parseCompanyRow(row)
    if (!c || seen.has(c.cmpId)) continue
    seen.add(c.cmpId)
    out.push(c)
  }
  return out
}

/** Reported total for pagination, falling back to the rows we actually got. */
export function companyListTotal(body: unknown, rows: CompanyOption[]): number {
  if (isRow(body)) {
    const meta = isRow(body.meta) ? body.meta : null
    const candidates = [meta?.total, meta?.total_count, body.total, body.total_count, isRow(body.data) ? body.data.total : undefined]
    for (const c of candidates) {
      const n = num(c)
      if (n !== null) return n
    }
  }
  return rows.length
}

export function parseFyRow(row: Row): FyOption | null {
  const fyId = num(row.fy_id) ?? num(row.comp_fy_id) ?? num(row.id)
  if (fyId === null || fyId <= 0) return null
  const start = toIsoDate(row.fy_start ?? row.fy_beg_date ?? row.fy_from ?? row.fy_start_date ?? row.start_date ?? row.from_date)
  const end = toIsoDate(row.fy_end ?? row.fy_end_date ?? row.fy_to ?? row.end_date ?? row.to_date)
  const fallback = str(row.fy_short) || str(row.fy_label) || str(row.label) || str(row.fy_name) || `FY #${fyId}`
  return {
    fyId,
    start,
    end,
    label: formatFyLabel(start, end, fallback),
    defaultValuationMethod: str(row.def_val_method) || null,
  }
}

export function parseBranchRow(row: Row): BranchOption | null {
  const boId = num(row.bo_id) ?? num(row.hobo_id) ?? num(row.branch_id) ?? num(row.id)
  if (boId === null || boId <= 0) return null
  const name = str(row.hobo_name) || str(row.bo_name) || str(row.branch_name) || str(row.name) || str(row.label) || `Branch #${boId}`
  return {
    boId,
    name,
    isHeadOffice: truthy(row.mark_ho) || truthy(row.is_ho) || truthy(row.is_head_office),
  }
}

/** Rows out of a `/manage/branch/list` envelope. */
export function parseBranchList(body: unknown): BranchOption[] {
  const rows = Array.isArray(body) ? rowsOf(body) : isRow(body) ? rowsOf(body.data ?? body.branches ?? body.branch_list) : []
  const seen = new Set<number>()
  const out: BranchOption[] = []
  for (const row of rows) {
    const b = parseBranchRow(row)
    if (!b || seen.has(b.boId)) continue
    seen.add(b.boId)
    out.push(b)
  }
  return out
}

/*
 * Letterhead fields.
 *
 * A printed challan or register carries the registered office and the GSTIN —
 * in India that is what makes the paper a document rather than a screenshot.
 * Manage has grown three shapes for the address over the years (a free-text
 * block, a structured object, flat `ro_*` / `co_*` fields on the root) and
 * half a dozen spellings for the GSTIN, so all of them are read here.
 */

const ADDRESS_KEYS = [
  'ro_address',
  'registered_office',
  'registered_office_address',
  'registered_address',
  'address',
  'cmp_address',
  'comp_address',
  'company_address',
  'office_address',
] as const

const GSTIN_KEYS = ['gstin', 'gst_no', 'gst_number', 'company_gstin', 'comp_gstin', 'cmp_gstin'] as const

const ADDRESS_FIELDS = [
  'addr1', 'addr2', 'address_line1', 'address_line2', 'line1', 'line2', 'street',
  'city', 'district', 'state_name', 'state', 'country_name', 'country', 'pincode', 'pin', 'zip',
] as const

/** A bare 1-3 digit value is a Manage master id, not a place name. */
function addressText(v: unknown): string {
  if (isRow(v)) return addressText(v.name ?? v.state_name ?? v.country_name ?? v.label ?? '')
  const text = str(v)
  return /^\d{1,3}$/.test(text) ? '' : text
}

/**
 * Pull `ro_city` / `co_pincode` up to `city` / `pincode` when the plain key is absent.
 *
 * Only keys that carry something are written. These rows are merged over one
 * another — the nested address object over the flat root fields — and a key
 * present with `undefined` wins that merge, so emitting one for every field
 * would blank the very root value this exists to find.
 */
function flattenAddress(row: Row): Row {
  const out: Row = {}
  for (const field of ADDRESS_FIELDS) {
    const value = row[field] ?? row[`ro_${field}`] ?? row[`co_${field}`]
    if (value !== undefined && value !== null && value !== '') out[field] = value
  }
  return out
}

interface AddressParts {
  street: string[]
  locality: string[]
  pin: string
  country: string
}

function addressParts(a: Row): AddressParts {
  return {
    street: [a.addr1, a.addr2, a.address_line1, a.address_line2, a.line1, a.line2, a.street]
      .map(addressText)
      .filter(Boolean),
    locality: [a.city, a.district, a.state_name ?? a.state].map(addressText).filter(Boolean),
    pin: addressText(a.pincode ?? a.pin ?? a.zip),
    country: addressText(a.country_name ?? a.country),
  }
}

/**
 * Enough of a postal address to stand as a registered office.
 *
 * The field scan reads the whole companyinfo row, where `state` and
 * `country_name` are masters every company carries whether or not Manage holds
 * an address. A letterhead reading "India" over a delivery challan is worse
 * than one with no address at all, so a premises line — or a PIN with the
 * locality it belongs to — has to be there before the scan speaks.
 */
function isPostalAddress(a: Row): boolean {
  const parts = addressParts(a)
  return parts.street.length > 0 || (Boolean(parts.pin) && parts.locality.length > 0)
}

function structuredAddressLines(a: Row): string[] {
  const { street, locality, pin, country } = addressParts(a)
  return [street.join(', '), [...locality, pin].filter(Boolean).join(', '), country].filter(Boolean)
}

/** The registered office as letterhead lines, from whichever shape Manage sent. */
export function parseCompanyAddress(body: unknown): string[] {
  for (const source of companySources(body)) {
    for (const key of ADDRESS_KEYS) {
      const value = source[key]
      if (typeof value === 'string' && value.trim()) {
        return value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean)
      }
      if (isRow(value)) {
        const merged = { ...flattenAddress(source), ...flattenAddress(value) }
        if (isPostalAddress(merged)) return structuredAddressLines(merged)
      }
    }
    const flat = flattenAddress(source)
    if (isPostalAddress(flat)) return structuredAddressLines(flat)
  }

  return []
}

export function parseCompanyGstin(body: unknown): string {
  for (const source of companySources(body)) {
    for (const key of GSTIN_KEYS) {
      const value = str(source[key])
      if (value) return value
    }
  }

  return ''
}

/** The nested objects a companyinfo payload may keep the profile fields in. */
function companySources(body: unknown): Row[] {
  const root = isRow(body) && isRow(body.data) ? body.data : isRow(body) ? body : {}
  const candidates = [root, root.company, root.profile, root.general_info, root.company_details]
  const out: Row[] = []
  for (const candidate of candidates) {
    if (isRow(candidate) && !out.includes(candidate)) out.push(candidate)
  }

  return out
}

/** `/manage/companyinfo?comp_id=` → name, financial years (latest first) and branches. */
export function parseCompanyInfo(body: unknown): CompanyInfo {
  const data = isRow(body) && isRow(body.data) ? body.data : isRow(body) ? body : {}
  const fyRows = rowsOf(data.fy_list ?? data.financial_years ?? data.fys)
  const fyList: FyOption[] = []
  const seenFy = new Set<number>()
  for (const r of fyRows) {
    const fy = parseFyRow(r)
    if (!fy || seenFy.has(fy.fyId)) continue
    seenFy.add(fy.fyId)
    fyList.push(fy)
  }
  fyList.sort((a, b) => (b.end || '').localeCompare(a.end || '') || b.fyId - a.fyId)

  const branches = parseBranchList(data.branch_list ?? data.bo_list ?? data.branches ?? [])
  const cmpId = num(data.cmp_id) ?? num(data.comp_id)
  return {
    cmpId,
    name: str(data.comp_name) || str(data.company_name) || str(data.cmp_name) || str(data.print_name) || '',
    fyList,
    branches,
    hoId: num(data.ho_id),
    addressLines: parseCompanyAddress(body),
    gstin: parseCompanyGstin(body),
  }
}

/** The financial year that ends last — the one a user almost always wants. */
export function pickLatestFy(list: FyOption[]): FyOption | null {
  if (list.length === 0) return null
  return [...list].sort((a, b) => (b.end || '').localeCompare(a.end || '') || b.fyId - a.fyId)[0]
}

/** The FY whose date range contains `isoDate` (today by default), else the latest. */
export function pickFyForDate(list: FyOption[], isoDate: string): FyOption | null {
  const hit = list.find((fy) => fy.start && fy.end && fy.start <= isoDate && isoDate <= fy.end)
  return hit ?? pickLatestFy(list)
}
