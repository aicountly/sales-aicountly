/**
 * The one race this hook exists to lose safely: switching company while a
 * request is in flight.
 *
 * A multi-tenant screen that keeps the previous company's answer on display
 * while the new one loads is showing one company's figures under another
 * company's name. It is the worst failure this product could have, it is
 * invisible in manual testing because the window is a few hundred milliseconds,
 * and it is exactly what this test pins down.
 */

import { act, cleanup, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useApi } from './useApi'

// Vitest is configured without globals, so Testing Library's automatic cleanup
// does not install itself. Without this, every test renders into the document
// the last one left behind and the queries find three of everything.
afterEach(cleanup)

function Probe({ company, fetcher }: { company: number; fetcher: (signal: AbortSignal) => Promise<string> }) {
  const { data, loading, error, reload } = useApi(fetcher, [company], true)

  return (
    <div>
      <span data-testid="company">{company}</span>
      <span data-testid="data">{data ?? 'none'}</span>
      <span data-testid="loading">{loading ? 'yes' : 'no'}</span>
      <span data-testid="error">{error ?? 'none'}</span>
      <button type="button" onClick={reload}>
        Retry
      </button>
    </div>
  )
}

describe('useApi', () => {
  it('drops the previous company’s answer the moment the company changes', async () => {
    const answers: Record<number, string> = { 1: 'company one figures', 2: 'company two figures' }
    const fetcher = vi.fn(async (_signal: AbortSignal) => answers[current])
    let current = 1

    const view = render(<Probe company={1} fetcher={fetcher} />)
    await waitFor(() => expect(screen.getByTestId('data').textContent).toBe('company one figures'))

    // Switch company. The new request has not answered yet.
    let release: (value: string) => void = () => {}
    const pending = new Promise<string>((resolve) => {
      release = resolve
    })
    current = 2
    view.rerender(<Probe company={2} fetcher={() => pending} />)

    // Company one's figures must NOT still be on screen under company two.
    expect(screen.getByTestId('company').textContent).toBe('2')
    expect(screen.getByTestId('data').textContent).toBe('none')

    await act(async () => {
      release('company two figures')
      await pending
    })
    await waitFor(() => expect(screen.getByTestId('data').textContent).toBe('company two figures'))
  })

  it('aborts the request it is leaving behind', async () => {
    const seen: AbortSignal[] = []
    const fetcher = (signal: AbortSignal) => {
      seen.push(signal)

      return new Promise<string>(() => {
        /* never settles: this is the request being abandoned */
      })
    }

    const view = render(<Probe company={1} fetcher={fetcher} />)
    await waitFor(() => expect(seen.length).toBe(1))

    view.rerender(<Probe company={2} fetcher={fetcher} />)
    await waitFor(() => expect(seen[0].aborted).toBe(true))
  })

  it('keeps what is on screen across a plain reload, which is the same question', async () => {
    let calls = 0
    let release: (value: string) => void = () => {}
    const fetcher = () => {
      calls += 1
      if (calls === 1) return Promise.resolve('answer 1')

      return new Promise<string>((resolve) => {
        release = resolve
      })
    }

    render(<Probe company={1} fetcher={fetcher} />)
    await waitFor(() => expect(screen.getByTestId('data').textContent).toBe('answer 1'))

    // Retrying is not a new question. Blanking the screen to retry it would be
    // a worse experience than the error the user is retrying from.
    act(() => screen.getByRole('button', { name: 'Retry' }).click())
    await waitFor(() => expect(calls).toBe(2))
    expect(screen.getByTestId('data').textContent).toBe('answer 1')

    await act(async () => {
      release('answer 2')
      await Promise.resolve()
    })
    await waitFor(() => expect(screen.getByTestId('data').textContent).toBe('answer 2'))
  })
})
