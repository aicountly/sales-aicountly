/**
 * Company, branch and financial-year truth, read live from Manage through this
 * product's own API (`/api/v1/manage/*`, read-only).
 *
 * Nothing here is cached or stored. The switcher asks on the request that draws
 * it, which is the only way a list of companies cannot go stale when someone is
 * added to one elsewhere.
 *
 * The payloads are normalised by manageShapes.ts, which is shared verbatim with
 * Inventory: Manage's list envelope has grown several shapes over the years and
 * the row fields differ between `companies` and `companyinfo`. Parsing it in
 * one audited place beats re-deriving it per product.
 */

import { api } from './api'
import { companyListTotal, parseCompanyInfo, parseCompanyList } from '../company/manageShapes'
import type { CompanyInfo, CompanyOption } from '../company/manageShapes'

const PER_PAGE = 100
const MAX_PAGES = 20

/**
 * Every company the signed-in user can open.
 *
 * Paged, because an accountant with three hundred clients is a real user and a
 * single unbounded response is how that person's switcher times out. MAX_PAGES
 * bounds it so a malformed `total` cannot spin forever.
 */
export async function fetchAllCompanies(signal?: AbortSignal): Promise<CompanyOption[]> {
  const merged: CompanyOption[] = []
  const seen = new Set<number>()
  let reportedTotal: number | null = null

  for (let page = 1; page <= MAX_PAGES; page += 1) {
    const body = await api.unscoped<unknown>('v1/manage/companies', {
      filter: 'all',
      page,
      per_page: PER_PAGE,
    }, signal)

    const rows = parseCompanyList(body)
    if (reportedTotal === null) reportedTotal = companyListTotal(body, rows)

    for (const row of rows) {
      if (seen.has(row.cmpId)) continue
      seen.add(row.cmpId)
      merged.push(row)
    }

    if (rows.length === 0 || rows.length < PER_PAGE || merged.length >= reportedTotal) break
  }

  return merged
}

/** One company, with its financial years and branches — Manage returns all three together. */
export async function fetchCompanyInfo(cmpId: number, signal?: AbortSignal): Promise<CompanyInfo> {
  const body = await api.unscoped<unknown>('v1/manage/companyinfo', { comp_id: cmpId }, signal)
  return parseCompanyInfo(body)
}

export type { BranchOption, CompanyInfo, CompanyOption, FyOption } from '../company/manageShapes'
