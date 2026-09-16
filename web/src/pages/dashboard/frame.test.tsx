/**
 * The KPI row's two honest states.
 *
 * A dashboard that 403s used to leave four cards reading "Loading…" for as long
 * as the tab stayed open: `loading` went false, `metrics` stayed undefined, and
 * the placeholder branch caught both. Somebody looking at that concludes the
 * app is slow and waits, when what actually happened is that the server refused
 * and said why — in a notice the placeholders were sitting on top of.
 *
 * Small test, narrow claim: a spinner must mean "still working".
 */

import { cleanup, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it } from 'vitest'
import { MetricsSection } from './frame'
import type { Metric } from '../../ui'

afterEach(cleanup)

function renderSection(props: { metrics: Metric[] | undefined; loading: boolean }) {
  return render(
    <MemoryRouter>
      <MetricsSection {...props} currency="INR" />
    </MemoryRouter>,
  )
}

const READY: Metric[] = [
  { id: 'open_quotations', label: 'Open quotations', status: 'ready', value: 4860000, unit: 'currency' },
]

describe('MetricsSection', () => {
  it('shows placeholders while the request is genuinely in flight', () => {
    renderSection({ metrics: undefined, loading: true })

    expect(screen.getAllByText('Loading…').length).toBeGreaterThan(0)
  })

  it('renders nothing once a failed load has finished, leaving the error to speak', () => {
    const view = renderSection({ metrics: undefined, loading: false })

    expect(view.container.textContent).toBe('')
    expect(screen.queryByText('Loading…')).toBeNull()
  })

  it('draws the metrics it was given', () => {
    renderSection({ metrics: READY, loading: false })

    expect(screen.getByText('Open quotations')).toBeTruthy()
    expect(screen.getByText('₹48.6L')).toBeTruthy()
  })

  it('a refused metric says it is restricted and gives the reason, never a zero', () => {
    renderSection({
      metrics: [
        {
          id: 'confirmed_orders',
          label: 'Confirmed orders',
          status: 'forbidden',
          value: null,
          unit: 'currency',
          reason: 'You do not have permission to view sales orders.',
        },
      ],
      loading: false,
    })

    expect(screen.getByText('Confirmed orders')).toBeTruthy()
    // "Restricted" and the reason, not a figure — the whole point of carrying a
    // status on a metric instead of a number and a hope.
    expect(screen.getByText('Restricted')).toBeTruthy()
    expect(screen.getByText('You do not have permission to view sales orders.')).toBeTruthy()
    expect(screen.queryByText('₹0')).toBeNull()
    expect(screen.queryByText('₹0.00')).toBeNull()
  })
})
