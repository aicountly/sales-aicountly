import { useEffect, useMemo, useRef, useState } from 'react'
import { Search } from 'lucide-react'
import { api } from '../services/api'
import type { CatalogCustomer, CatalogItem } from '../services/types'

/**
 * Type-ahead pickers for records another product owns.
 *
 * Every keystroke asks Inventory (items) or Books (customers) through this
 * product's read-through endpoint. Nothing is prefetched into a local list and
 * nothing is kept after the user has chosen: what is kept is the id.
 *
 * That is why the search is debounced and why it needs two characters — the
 * cost of asking is a network call, so we do not make one per keystroke.
 */

function useDebounced<T>(value: T, ms: number): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), ms)
    return () => clearTimeout(timer)
  }, [value, ms])
  return debounced
}

interface PickerProps<T> {
  label: string
  placeholder?: string
  selectedLabel?: string | null
  onPick: (record: T) => void
}

function Picker<T>({
  label,
  placeholder,
  selectedLabel,
  onPick,
  search,
  renderOption,
  keyOf,
}: PickerProps<T> & {
  search: (term: string, signal: AbortSignal) => Promise<T[]>
  renderOption: (record: T) => string
  keyOf: (record: T) => string | number
}) {
  const [term, setTerm] = useState('')
  const [options, setOptions] = useState<T[]>([])
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState(false)
  const debounced = useDebounced(term, 250)
  const boxRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (debounced.trim().length < 2) {
      setOptions([])
      return
    }

    const controller = new AbortController()
    setBusy(true)
    setFailed(false)

    search(debounced.trim(), controller.signal)
      .then((rows) => setOptions(rows))
      .catch(() => {
        if (!controller.signal.aborted) setFailed(true)
      })
      .finally(() => setBusy(false))

    return () => controller.abort()
  }, [debounced, search])

  useEffect(() => {
    function onClickAway(event: MouseEvent) {
      if (boxRef.current && !boxRef.current.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onClickAway)
    return () => document.removeEventListener('mousedown', onClickAway)
  }, [])

  return (
    <div ref={boxRef} style={{ position: 'relative' }}>
      <span style={{ display: 'block', fontSize: 12, color: 'var(--muted)', marginBottom: 6 }}>
        {label}
        <span className="sales-required" aria-hidden>
          *
        </span>
      </span>
      <div style={{ position: 'relative' }}>
        <Search
          size={14}
          aria-hidden
          style={{ position: 'absolute', left: '0.5rem', top: '50%', transform: 'translateY(-50%)', color: 'var(--muted)' }}
        />
        <input
          value={term}
          placeholder={selectedLabel ?? placeholder ?? 'Type to search…'}
          aria-label={label}
          onChange={(event) => {
            setTerm(event.target.value)
            setOpen(true)
          }}
          onFocus={() => setOpen(true)}
          style={{
            width: '100%',
            minHeight: 40,
            padding: '9px 12px 9px 32px',
            border: `1px solid ${selectedLabel ? '#80b977' : 'var(--line-strong, var(--border-strong))'}`,
            borderRadius: 8,
            background: 'var(--surface)',
          }}
        />
      </div>

      {open && term.trim().length >= 2 && (
        <div
          style={{
            position: 'absolute',
            zIndex: 20,
            top: '100%',
            left: 0,
            right: 0,
            marginTop: 4,
            background: 'var(--surface)',
            border: '1px solid var(--border-strong)',
            borderRadius: 10,
            boxShadow: 'var(--shadow-lg)',
            overflow: 'hidden',
            maxHeight: '16rem',
            overflowY: 'auto',
          }}
        >
          {busy && <div style={{ padding: '0.6rem', color: 'var(--muted)' }}>Searching…</div>}
          {failed && (
            <div style={{ padding: '0.6rem', color: 'var(--danger)' }}>
              Could not reach the app that owns this list. Try again in a moment.
            </div>
          )}
          {!busy && !failed && options.length === 0 && (
            <div style={{ padding: '10px 12px', color: 'var(--muted)' }}>
              No matches. This list is read live from the product that owns it, so a brand-new record shows here as
              soon as it is saved there.
            </div>
          )}
          {options.map((option) => (
            <button
              key={keyOf(option)}
              type="button"
              onClick={() => {
                onPick(option)
                setTerm('')
                setOpen(false)
              }}
              style={{
                display: 'block',
                width: '100%',
                textAlign: 'left',
                padding: '9px 12px',
                border: 'none',
                borderBottom: '1px solid var(--border)',
                background: 'transparent',
                cursor: 'pointer',
                fontSize: 13,
              }}
            >
              {renderOption(option)}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

export function ItemPicker({ onPick, selectedLabel }: { onPick: (item: CatalogItem) => void; selectedLabel?: string | null }) {
  const search = useMemo(
    () => async (term: string, signal: AbortSignal) => {
      const response = await api.list<CatalogItem>('v1/catalog/items/search', { q: term }, signal)
      return response.data
    },
    [],
  )

  return (
    <Picker
      label="Item"
      placeholder="Search Inventory…"
      selectedLabel={selectedLabel}
      onPick={onPick}
      search={search}
      keyOf={(item) => item.item_id}
      renderOption={(item) => (item.item_sku ? `${item.item_name} · ${item.item_sku}` : item.item_name)}
    />
  )
}

export function CustomerPicker({
  onPick,
  selectedLabel,
}: {
  onPick: (customer: CatalogCustomer) => void
  selectedLabel?: string | null
}) {
  const search = useMemo(
    () => async (term: string, signal: AbortSignal) => {
      const response = await api.list<CatalogCustomer>('v1/catalog/customers', { q: term }, signal)
      return response.data
    },
    [],
  )

  return (
    <Picker
      label="Customer"
      placeholder="Search Books party ledgers…"
      selectedLabel={selectedLabel}
      onPick={onPick}
      search={search}
      keyOf={(customer) => customer.acc_id}
      renderOption={(customer) => (customer.gstin ? `${customer.acc_name} · ${customer.gstin}` : customer.acc_name)}
    />
  )
}
