import { useCallback, useEffect, useRef, useState } from 'react'
import { Building2, Loader2 } from 'lucide-react'
import { Button, Field, Notice, Select } from '../ui'
import { fetchAllCompanies, fetchCompanyInfo } from '../services/manage'
import type { CompanyInfo, CompanyOption } from '../services/manage'
import { useSales } from '../context/SalesContext'

/**
 * Which company, branch and financial year this product is working in.
 *
 * THE LIST IS READ FROM MANAGE, LIVE, every time this opens. Manage owns
 * companies, branches and financial years; this product stores their ids and
 * nothing else. Showing someone a list is a read, not a copy — and a list read
 * on the request that draws it cannot go stale the way a local `companies`
 * table would the moment somebody is granted access elsewhere.
 *
 * Manage also decides WHICH companies come back, because the call carries the
 * signed-in user's own session key. This product never filters that list and is
 * never the thing deciding what someone may open.
 */
export function CompanyPicker() {
  const { scope, setCompanyScope } = useSales()

  const [open, setOpen] = useState(!scope)
  const [companies, setCompanies] = useState<CompanyOption[]>([])
  const [info, setInfo] = useState<CompanyInfo | null>(null)
  const [loadingList, setLoadingList] = useState(false)
  const [loadingInfo, setLoadingInfo] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const [cmpId, setCmpId] = useState<number | null>(scope?.cmp_id ?? null)
  const [fyId, setFyId] = useState<number | null>(scope?.fy_id ?? null)
  const [boId, setBoId] = useState<number>(scope?.bo_id ?? 0)

  /** The label shown when collapsed. Display only, fetched live, never stored. */
  const [currentLabel, setCurrentLabel] = useState<string | null>(null)

  const abortRef = useRef<AbortController | null>(null)

  // ---------------------------------------------------------------- list

  const loadCompanies = useCallback(async () => {
    setLoadingList(true)
    setError(null)
    try {
      const rows = await fetchAllCompanies()
      setCompanies(rows)
      // One company and nothing chosen yet: choose it. Making someone pick from
      // a list of one is a pointless click.
      if (rows.length === 1 && cmpId === null) setCmpId(rows[0].cmpId)
    } catch (e) {
      setError(
        e instanceof Error
          ? `Could not load your companies: ${e.message}`
          : 'Could not load your companies.',
      )
    } finally {
      setLoadingList(false)
    }
  }, [cmpId])

  useEffect(() => {
    if (open) void loadCompanies()
    // loadCompanies is intentionally omitted: it changes with cmpId, and
    // reloading the whole list every time someone picks a company would be a
    // request per keystroke of the dropdown.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  // ---------------------------------------------------------------- one company

  useEffect(() => {
    if (cmpId === null) {
      setInfo(null)
      return
    }

    abortRef.current?.abort()
    const controller = new AbortController()
    abortRef.current = controller

    setLoadingInfo(true)
    fetchCompanyInfo(cmpId, controller.signal)
      .then((next) => {
        if (controller.signal.aborted) return
        setInfo(next)

        // Default to the most recent financial year — the list arrives sorted
        // newest first — unless the current choice is still valid.
        setFyId((prev) =>
          prev !== null && next.fyList.some((f) => f.fyId === prev)
            ? prev
            : (next.fyList[0]?.fyId ?? null),
        )
        setBoId((prev) => (prev !== 0 && !next.branches.some((b) => b.boId === prev) ? 0 : prev))
      })
      .catch((e: unknown) => {
        if (controller.signal.aborted) return
        setInfo(null)
        setError(
          e instanceof Error
            ? `Could not load that company's years and branches: ${e.message}`
            : "Could not load that company's years and branches.",
        )
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoadingInfo(false)
      })

    return () => controller.abort()
  }, [cmpId])

  // Label for the collapsed state, so the header says which company is open
  // rather than showing a bare number.
  useEffect(() => {
    if (!scope) {
      setCurrentLabel(null)
      return
    }
    let cancelled = false
    fetchCompanyInfo(scope.cmp_id)
      .then((i) => {
        if (cancelled) return
        const fy = i.fyList.find((f) => f.fyId === scope.fy_id)
        setCurrentLabel([i.name || `Company ${scope.cmp_id}`, fy?.label].filter(Boolean).join(' · '))
      })
      .catch(() => {
        // Manage unreachable is not worth an error here; the ids still work and
        // the scoped screens will report it properly if it matters.
        if (!cancelled) setCurrentLabel(`Company ${scope.cmp_id}`)
      })
    return () => {
      cancelled = true
    }
  }, [scope])

  function apply() {
    if (cmpId === null || fyId === null) return
    setCompanyScope({ cmp_id: cmpId, fy_id: fyId, bo_id: boId })
    setOpen(false)
  }

  // ---------------------------------------------------------------- render

  if (!open) {
    return (
      <Button tone="ghost" onClick={() => setOpen(true)}>
        <Building2 size={15} aria-hidden />
        {currentLabel ?? 'Change company'}
      </Button>
    )
  }

  return (
    <div style={{ display: 'grid', gap: '0.5rem', minWidth: 0 }}>
      {error && <Notice tone="danger" onDismiss={() => setError(null)}>{error}</Notice>}

      <div style={{ display: 'flex', alignItems: 'flex-end', gap: '0.5rem', flexWrap: 'wrap' }}>
        <div style={{ minWidth: '14rem' }}>
          <Field label="Company">
            <Select
              value={cmpId ?? ''}
              disabled={loadingList}
              onChange={(e) => setCmpId(e.target.value === '' ? null : Number(e.target.value))}
            >
              <option value="">{loadingList ? 'Loading…' : 'Choose a company'}</option>
              {companies.map((c) => (
                <option key={c.cmpId} value={c.cmpId}>
                  {c.name}
                  {c.ownership === 'shared' ? ' (shared)' : ''}
                </option>
              ))}
            </Select>
          </Field>
        </div>

        <div style={{ minWidth: '10rem' }}>
          <Field label="Financial year">
            <Select
              value={fyId ?? ''}
              disabled={cmpId === null || loadingInfo}
              onChange={(e) => setFyId(e.target.value === '' ? null : Number(e.target.value))}
            >
              <option value="">{loadingInfo ? 'Loading…' : 'Choose a year'}</option>
              {(info?.fyList ?? []).map((f) => (
                <option key={f.fyId} value={f.fyId}>
                  {f.label}
                </option>
              ))}
            </Select>
          </Field>
        </div>

        <div style={{ minWidth: '10rem' }}>
          <Field label="Branch">
            <Select
              value={boId}
              disabled={cmpId === null || loadingInfo}
              onChange={(e) => setBoId(Number(e.target.value) || 0)}
            >
              <option value={0}>All branches</option>
              {(info?.branches ?? []).map((b) => (
                <option key={b.boId} value={b.boId}>
                  {b.name}
                  {b.isHeadOffice ? ' (head office)' : ''}
                </option>
              ))}
            </Select>
          </Field>
        </div>

        <Button tone="primary" onClick={apply} disabled={cmpId === null || fyId === null}>
          {loadingInfo ? <Loader2 size={14} className="spin" aria-hidden /> : null} Open
        </Button>

        {scope && (
          <Button tone="ghost" onClick={() => setOpen(false)}>
            Cancel
          </Button>
        )}
      </div>

      {!loadingList && companies.length === 0 && !error && (
        <Notice tone="warning" title="No companies">
          Manage has no companies for this sign-in. Create one in Aicountly Manage, or ask whoever
          owns the company to give you access — this product cannot grant it.
        </Notice>
      )}

      {cmpId !== null && !loadingInfo && info !== null && info.fyList.length === 0 && (
        <Notice tone="warning" title="No financial year">
          That company has no financial year set up yet. Add one in Aicountly Manage; every document
          here is filed against a year, so there is nothing this product can do until one exists.
        </Notice>
      )}
    </div>
  )
}
