import { useCallback, useEffect, useMemo, useState } from 'react';
import api from '../../api';
import Dropdown from '../../components/Admin/Dropdown';
import ExportMenu from './report/ExportMenu';
import RevenueTrendChart from './report/RevenueTrendChart';
import ScentProfileChart from './report/ScentProfileChart';
import TopProductsTable from './report/TopProductsTable';
import TransactionsTable from './report/TransactionsTable';
import {
  RANGE_PRESETS, DEFAULT_PRESET, presetFor, toInputDate, formatRangeDate,
} from './report/constants';
 
const POLL_INTERVAL_MS = 3 * 60 * 1000;
 
export default function SalesReport() {
  const initial = presetFor(DEFAULT_PRESET);
  const [initialFrom, initialTo] = initial.resolve();
 
  // `applied` is what the server has been asked for; `draft*` is what's sitting
  // in the custom date inputs. Keeping them separate is what stops a
  // half-entered date from firing a request per keystroke.
  const [applied, setApplied] = useState({
    from: toInputDate(initialFrom),
    to: toInputDate(initialTo),
    granularity: initial.granularity,
  });
  const [preset, setPreset] = useState(DEFAULT_PRESET);
  const [draftFrom, setDraftFrom] = useState(toInputDate(initialFrom));
  const [draftTo, setDraftTo] = useState(toInputDate(initialTo));
 
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(true);
 
  const fetchReport = useCallback((params, signal) => {
    return api
      .get('/admin/sales-report/range', {
        params: { from: params.from, to: params.to, granularity: params.granularity },
        signal,
      })
      .then(({ data }) => setReport(data))
      .catch((err) => {
        if (err.name !== 'CanceledError') console.error('Failed to load sales report:', err);
      });
  }, []);
 
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    fetchReport(applied, controller.signal).finally(() => setLoading(false));
    return () => controller.abort();
  }, [applied, fetchReport]);
 
  useEffect(() => {
    function refreshIfVisible() {
      if (document.hidden) return;
      fetchReport(applied);
    }
 
    const id = setInterval(refreshIfVisible, POLL_INTERVAL_MS);
    document.addEventListener('visibilitychange', refreshIfVisible);
 
    return () => {
      clearInterval(id);
      document.removeEventListener('visibilitychange', refreshIfVisible);
    };
  }, [applied, fetchReport]);
 
  function handlePresetChange(value) {
    setPreset(value);
 
    const option = presetFor(value);
    if (!option.resolve) return; // Custom Range — the inputs take over
 
    const [from, to] = option.resolve();
    const nextFrom = toInputDate(from);
    const nextTo = toInputDate(to);
 
    setDraftFrom(nextFrom);
    setDraftTo(nextTo);
    setApplied({ from: nextFrom, to: nextTo, granularity: option.granularity });
  }
 
  /**
   * A custom range applies itself as soon as both dates are valid.
   *
   * There's no Apply button because there's nothing to protect against:
   * <input type="date"> only fires change on a COMPLETE date, so this can't
   * fire a request per keystroke the way a free-text field would. The button
   * was solving a problem the input already solves.
   *
   * Guarded on an actual difference so switching presets — which also writes
   * the draft dates — doesn't trigger a second, identical fetch.
   */
  useEffect(() => {
    if (preset !== 'custom' || !draftFrom || !draftTo) return;
 
    // Tolerate a backwards pair rather than rejecting it — filling the second
    // field first is a slip, not something worth an error message.
    const [from, to] = draftFrom <= draftTo ? [draftFrom, draftTo] : [draftTo, draftFrom];
    if (from === applied.from && to === applied.to) return;
 
    setApplied({ from, to, granularity: presetFor('custom').granularity });
  }, [preset, draftFrom, draftTo, applied.from, applied.to]);
 
  const series = report?.revenue_series ?? [];
 
  // Averaged across every bucket in the range, including empty ones — a week
  // with no sales is a real data point, and dropping it would flatter the
  // average into meaninglessness.
  const average = useMemo(() => {
    if (series.length === 0) return 0;
    return Math.round(series.reduce((sum, p) => sum + (p.revenue || 0), 0) / series.length);
  }, [series]);
 
  const isCustom = preset === 'custom';
  const maxDate = toInputDate(new Date());
 
  const lastRun = report?.generated_at
    ? new Date(report.generated_at).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short' })
    : null;
 
  return (
    <div className="text-nature-dark space-y-6">
      {/* ---------------- Header: title + every control ----------------
          The period dropdown, both exports and Run Batch share one row. An
          earlier version put them in a separate card below the title, which
          cost a full band of vertical space to hold three controls. */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="font-serif text-3xl">Sales Report</h1>
          {/* Batch freshness. This page reads pre-aggregated COBOL output, not
              the live orders table, so an order placed a minute ago isn't here
              yet — this line is the answer to "why does this say 4 units when I
              know we sold 5?" */}
          <p
            className="text-nature-muted text-sm mt-0.5"
            title="Revenue, orders and units count completed orders only. The transaction log lists every status."
          >
            {lastRun ? `Data as of ${lastRun}` : 'No data yet — run the batch job to generate this report'}
          </p>
        </div>
 
        <div className="flex flex-wrap items-center gap-2.5">
          {/* Window first, then the control that sets it: the dates are the
              answer, the dropdown is how you change it. Same slot whether the
              window is editable or not, so switching between a preset and
              Custom swaps the control in place rather than moving a whole row
              on and off the page.
 
              No leading calendar icon on the inputs: each one already renders
              the browser's own picker button, and a third glyph just added
              noise. */}
          {isCustom ? (
            <div className="inline-flex items-center gap-1 bg-nature-card border border-nature-border rounded-lg pl-3 pr-2 py-1.5">
              <input
                type="date"
                value={draftFrom}
                max={draftTo || maxDate}
                onChange={(e) => setDraftFrom(e.target.value)}
                aria-label="From date"
                className="bg-transparent text-sm outline-none text-nature-dark"
              />
              <span className="text-nature-subtle text-xs px-0.5">–</span>
              <input
                type="date"
                value={draftTo}
                min={draftFrom || undefined}
                max={maxDate}
                onChange={(e) => setDraftTo(e.target.value)}
                aria-label="To date"
                className="bg-transparent text-sm outline-none text-nature-dark"
              />
            </div>
          ) : (
            /* Dates only — the dropdown label already says "Monthly", so
               repeating "Last 12 months" here said nothing the span didn't. */
            <p className="text-nature-muted text-sm whitespace-nowrap px-1">
              {formatRangeDate(report?.range?.from ?? applied.from)}
              <span className="text-nature-subtle"> – </span>
              {formatRangeDate(report?.range?.to ?? applied.to)}
            </p>
          )}
 
          <div className="w-[152px]">
            <Dropdown
              value={preset}
              onChange={handlePresetChange}
              options={RANGE_PRESETS.map((p) => ({ value: p.value, label: p.label }))}
            />
          </div>
 
          <ExportMenu
            from={applied.from}
            to={applied.to}
            granularity={applied.granularity}
            disabled={loading}
          />
        </div>
      </div>
 
      {/* ---------------- Data tables ----------------
          Above the charts, because these are what the page is for. Both filter
          and sort independently, and both foot to totals over their filtered
          rows. */}
      <TopProductsTable products={report?.top_products} loading={loading} />
      <TransactionsTable transactions={report?.transactions} loading={loading} />
 
      {/* ---------------- Charts ----------------
          2:1 split — the trend is read first and needs the horizontal room;
          the scent breakdown is a short ranked list of bars and doesn't. */}
      <div className="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <div className="xl:col-span-2">
          <RevenueTrendChart
            series={series}
            granularity={report?.range?.granularity ?? applied.granularity}
            average={average}
            loading={loading}
          />
        </div>
        <ScentProfileChart breakdown={report?.scent_breakdown} loading={loading} />
      </div>
    </div>
  );
}
 
 