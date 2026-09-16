/**
 * The formatting rules the dashboards depend on being right.
 *
 * These are small, and that is the point: "Unavailable" rendering as "₹0" is a
 * one-character mistake that would put a fictional figure on a credit
 * controller's screen, and no amount of integration testing catches it as
 * directly as asserting it here.
 */

import { describe, expect, it } from 'vitest'
import { formatMetric, money, moneyShort, relativeDays, type Metric } from './index'

function metric(overrides: Partial<Metric>): Metric {
  return {
    id: 'test',
    label: 'Test',
    status: 'ready',
    value: 0,
    unit: 'currency',
    ...overrides,
  }
}

describe('moneyShort', () => {
  it('speaks in lakhs and crores, which is how these figures are read', () => {
    expect(moneyShort(4863921)).toBe('₹48.6L')
    expect(moneyShort(90000000)).toBe('₹9.0Cr')
    expect(moneyShort(84200)).toBe('₹84,200')
  })

  it('keeps the sign, so a credit note does not read as income', () => {
    expect(moneyShort(-250000)).toBe('-₹2.5L')
  })

  it('leaves a non-INR currency to Intl rather than inventing lakhs for it', () => {
    expect(moneyShort(4863921, 'USD')).toContain('$')
    expect(moneyShort(4863921, 'USD')).not.toContain('L')
  })
})

describe('money', () => {
  it('groups in the Indian style and carries the document currency', () => {
    expect(money(123456.78)).toBe('₹1,23,456.78')
    expect(money('2500', 'USD')).toContain('2,500')
  })

  it('returns a dash for something that is not a number', () => {
    expect(money(null)).toBe('₹0.00')
    expect(money('not a number')).toBe('—')
  })
})

describe('formatMetric', () => {
  it('formats a ready currency metric in short form', () => {
    expect(formatMetric(metric({ value: 4860000 }), 'INR')).toBe('₹48.6L')
  })

  it('never turns a missing value into a zero', () => {
    // This is the whole rule. "Books did not answer" and "no sales this month"
    // are different facts, and a zero that means the first is a lie somebody
    // acts on.
    expect(formatMetric(metric({ value: null, status: 'unavailable' }), 'INR')).toBe('—')
    expect(formatMetric(metric({ value: null, status: 'forbidden' }), 'INR')).toBe('—')
  })

  it('rounds a percentage to one place rather than showing float noise', () => {
    expect(formatMetric(metric({ value: 35.6666, unit: 'percent' }), 'INR')).toBe('35.7%')
  })

  it('counts are plain integers, not currency', () => {
    expect(formatMetric(metric({ value: 1234, unit: 'count' }), 'INR')).toBe('1,234')
  })
})

describe('relativeDays', () => {
  it('reads a promise date the way a person would say it', () => {
    expect(relativeDays(0)).toBe('today')
    expect(relativeDays(1)).toBe('tomorrow')
    expect(relativeDays(-1)).toBe('yesterday')
    expect(relativeDays(5)).toBe('in 5 days')
    expect(relativeDays(-12)).toBe('12 days ago')
  })

  it('has no answer when there is no date, and says so', () => {
    expect(relativeDays(null)).toBe('—')
  })
})
