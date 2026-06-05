/** Locale-aware formatting helpers (tr-TR). */

const TR = 'tr-TR';

export function formatNumber(value: number): string {
  return new Intl.NumberFormat(TR).format(Number.isFinite(value) ? value : 0);
}

export function formatCurrency(value: number, currency = 'TRY'): string {
  return new Intl.NumberFormat(TR, {
    style: 'currency',
    currency,
    maximumFractionDigits: 0,
  }).format(Number.isFinite(value) ? value : 0);
}

/** Compact form for large counts, e.g. 1_250_000 → "1,3 Mn". */
export function formatCompact(value: number): string {
  return new Intl.NumberFormat(TR, {
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(Number.isFinite(value) ? value : 0);
}

export function formatPercent(value: number): string {
  const v = Number.isFinite(value) ? value : 0;
  return `%${new Intl.NumberFormat(TR, { maximumFractionDigits: 1 }).format(v)}`;
}

export function formatBytes(bytes: number): string {
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  let value = Number.isFinite(bytes) ? bytes : 0;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }
  return `${new Intl.NumberFormat(TR, { maximumFractionDigits: 1 }).format(value)} ${units[unit]}`;
}
