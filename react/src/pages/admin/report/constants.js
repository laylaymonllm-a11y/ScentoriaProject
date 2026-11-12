// Shared config + label helpers for the admin Sales Report page.
 
// -----------------------------------------------------------------------
// Card shell classes.
//
// Every surface on the report uses the same glass card as the rest of the
// admin (dashboard, orders, products), so it's defined once here instead of
// being retyped in six components and slowly drifting apart.
// -----------------------------------------------------------------------
export const CARD =
  'bg-white/30 backdrop-blur-xl border border-white/60 rounded-2xl shadow-[0_2px_16px_-4px_rgba(44,53,39,0.08)]';
 
export const SECTION_LABEL =
  'text-[10px] font-bold tracking-[0.15em] uppercase text-nature-olive';
 
export const TH =
  'text-left text-[10px] font-semibold tracking-[0.15em] uppercase text-nature-muted px-5 py-4 whitespace-nowrap';
 
// Theme palette, as literals — recharts needs real colour strings and can't
// read the Tailwind @theme CSS variables from index.css.
export const OLIVE = '#4A6838';
export const OLIVE_DARK = '#3A5530';
export const SAGE = '#B4C4A8';
export const GRID = '#E0DACD';
export const AXIS_TEXT = '#7A8573';
 
// Bar fills for the scent-profile chart. Deliberately a single-hue ramp from
// olive to sage rather than a rainbow: the bars are one measure (revenue), so
// varied hues would imply a category difference that isn't there, and the ramp
// still reinforces the descending sort.
export const SCENT_RAMP = [
  '#3A5530',
  '#4A6838',
  '#5A7B46',
  '#6B8E55',
  '#7FA066',
  '#94B27C',
  '#A9C094',
  '#BECEAC',
];
 
// -----------------------------------------------------------------------
// Date range presets
//
// `resolve` returns [from, to] as Date objects. Each one is a closure rather
// than a precomputed value so "Today" stays correct in a tab left open
// overnight.
// -----------------------------------------------------------------------
function daysAgo(n) {
  const d = new Date();
  d.setHours(0, 0, 0, 0);
  d.setDate(d.getDate() - n);
  return d;
}
 
function today() {
  const d = new Date();
  d.setHours(0, 0, 0, 0);
  return d;
}
 
/**
 * Nothing predates this, so it stands in for "the beginning of time" on the
 * All Time preset. The API needs a concrete `from`; omitting it would make the
 * controller default to the current month instead.
 */
const EPOCH = new Date(2000, 0, 1);
 
/**
 * The period dropdown.
 *
 * Each entry resolves to BOTH a date window and a bucket size, because on this
 * page they're one decision — "Weekly" is meaningless without saying weekly
 * over what span. Windows deliberately match AdminDashboard.jsx's existing
 * day/month/all convention so the two pages never disagree about what
 * "Monthly" covers.
 *
 * `resolve: null` marks Custom Range, which is what the header keys off to
 * reveal the from/to inputs.
 */
export const RANGE_PRESETS = [
  {
    value: 'daily',
    label: 'Daily',
    granularity: 'day',
    hint: 'Last 30 days',
    resolve: () => [daysAgo(29), today()],
  },
  {
    value: 'weekly',
    label: 'Weekly',
    granularity: 'week',
    hint: 'Last 12 weeks',
    // 11 weeks back plus the current partial week = 12 buckets.
    resolve: () => [startOfWeek(daysAgo(77)), today()],
  },
  {
    value: 'monthly',
    label: 'Monthly',
    granularity: 'month',
    hint: 'Last 12 months',
    resolve: () => {
      const now = today();
      return [new Date(now.getFullYear(), now.getMonth() - 11, 1), now];
    },
  },
  {
    value: 'all',
    label: 'All Time',
    granularity: 'year',
    hint: 'Every recorded year',
    resolve: () => [EPOCH, today()],
  },
  {
    value: 'custom',
    label: 'Custom Range…',
    // Custom ranges get day buckets: someone entering explicit dates is
    // almost always looking at a short window.
    granularity: 'day',
    hint: null,
    resolve: null,
  },
];
 
export const DEFAULT_PRESET = 'monthly';
 
/** Monday-anchored, matching Carbon's startOfWeek() on the server. */
function startOfWeek(date) {
  const d = new Date(date);
  // getDay() is 0 for Sunday, so shift it to make Monday the origin.
  const offset = (d.getDay() + 6) % 7;
  d.setDate(d.getDate() - offset);
  d.setHours(0, 0, 0, 0);
  return d;
}
 
export function presetFor(value) {
  return RANGE_PRESETS.find((p) => p.value === value) ?? RANGE_PRESETS[0];
}
 
// Status styles and filter options were removed with the status column. This
// report is completed orders only, so there is no status to badge or filter —
// pages/admin/orders/constants.js still owns those for the Orders page, which
// is where status-agnostic browsing belongs.
 
// Payment methods come from the orders.payment_method enum, carried through
// the COBOL job. Labelled for humans — nobody reconciling accounts wants to
// read "virtual_currency".
export const PAYMENT_LABELS = {
  cash: 'Cash',
  virtual_currency: 'Wallet',
};
 
export const PAYMENT_FILTER_OPTIONS = [
  { value: '', label: 'All Payments' },
  { value: 'cash', label: 'Cash' },
  { value: 'virtual_currency', label: 'Wallet' },
];
 
/** Unknown or newly-added methods degrade to a title-cased version. */
export function paymentLabel(method) {
  if (!method) return '—';
  return PAYMENT_LABELS[method] || method.replace(/_/g, ' ');
}
 
export const PAYMENT_STYLES = {
  cash: 'bg-stone-100 text-stone-700',
  virtual_currency: 'bg-violet-100 text-violet-700',
};
 
// -----------------------------------------------------------------------
// Formatting
// -----------------------------------------------------------------------
 
/** Date -> "2026-07-30", for <input type="date"> and API params. */
export function toInputDate(date) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}
 
/**
 * Series keys come straight out of the batch-computed report tables, and their
 * shape depends on the bucket size:
 *   day   "20260730"  (YYYYMMDD)
 *   week  "20260727"  (YYYYMMDD of the bucket's Monday)
 *   month "202607"    (YYYYMM)
 *   year  "2026"      (YYYY)
 *
 * Anything unexpected is handed back untouched rather than rendered as
 * "Invalid Date".
 */
export function formatPeriodLabel(granularity, key, { long = false } = {}) {
  if (!key) return '';
 
  if (granularity === 'year') {
    return key;
  }
 
  if (granularity === 'month') {
    if (key.length !== 6) return key;
    const d = new Date(Number(key.slice(0, 4)), Number(key.slice(4, 6)) - 1, 1);
    return d.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
  }
 
  if (key.length !== 8) return key;
  const d = new Date(Number(key.slice(0, 4)), Number(key.slice(4, 6)) - 1, Number(key.slice(6, 8)));
 
  // Week buckets are prefixed so a tooltip reading "30 Jul" can't be mistaken
  // for a single day's takings when it's actually seven days' worth.
  if (granularity === 'week') {
    const label = d.toLocaleDateString('en-US', { day: 'numeric', month: 'short' });
    return long ? `Week of ${label}` : label;
  }
 
  return d.toLocaleDateString('en-US',
    long
      ? { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }
      : { day: 'numeric', month: 'short' });
}
 
/** "2026-07-30" -> "30 Jul 2026", for the range summary line. */
export function formatRangeDate(iso) {
  if (!iso) return '';
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('en-US', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  });
}
 
/** Compact axis labels: 1_200_000 -> "1.2M", 45_000 -> "45k". */
export function formatCompact(value) {
  const n = Number(value) || 0;
  if (Math.abs(n) >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`;
  if (Math.abs(n) >= 1_000) return `${Math.round(n / 1_000)}k`;
  return String(n);
}
 
/** Plain thousands separator, for counts that aren't currency. */
export function formatCount(value) {
  return new Intl.NumberFormat('en-US').format(Number(value) || 0);
}
 
 