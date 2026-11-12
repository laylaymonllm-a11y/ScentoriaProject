import {
  AreaChart, Area, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid, ReferenceLine,
} from 'recharts';
import { Activity } from 'lucide-react';
import { formatMMK } from '../../../utils/currency';
import {
  CARD, SECTION_LABEL, OLIVE, GRID, AXIS_TEXT,
  formatPeriodLabel, formatCompact, formatCount,
} from './constants';
 
// Noun used in "Averaging X per <unit>".
const UNIT = { day: 'day', week: 'week', month: 'month', year: 'year' };
 
/**
 * Tooltip shows all three measures, not just revenue.
 *
 * On a sales report the interesting question is usually *why* a day spiked —
 * and "8 orders" versus "1 big order" are completely different stories that a
 * revenue-only tooltip can't tell apart.
 */
function TrendTooltip({ active, payload, granularity }) {
  if (!active || !payload?.length) return null;
  const point = payload[0].payload;
 
  return (
    <div className="bg-white/95 backdrop-blur-xl border border-nature-border/70 rounded-xl px-3.5 py-2.5 shadow-xl">
      <p className="text-[10px] text-nature-muted mb-1.5 tracking-wide">
        {formatPeriodLabel(granularity, point.label, { long: granularity === 'day' })}
      </p>
      <p className="text-sm font-semibold text-nature-olive mb-1.5">{formatMMK(point.revenue)}</p>
      <div className="flex items-center gap-3 text-[11px] text-nature-muted border-t border-nature-border/50 pt-1.5">
        <span>{formatCount(point.orders)} orders</span>
        <span className="w-px h-3 bg-nature-border" />
        <span>{formatCount(point.units)} units</span>
      </div>
    </div>
  );
}
 
export default function RevenueTrendChart({ series = [], granularity, average, loading }) {
  if (loading) {
    return <div className={`${CARD} h-[360px] animate-pulse`} />;
  }
 
  const hasRevenue = series.some((p) => p.revenue > 0);
 
  // Thin the x-axis to ~10 labels so a 90-day range doesn't render an
  // unreadable smear of overlapping dates.
  const step = Math.max(Math.ceil(series.length / 10), 1);
 
  return (
    <div className={`${CARD} p-6`}>
      {/* No interval toggle here any more. The header's period dropdown picks
          the window and the bucket size together, so a second control would be
          able to contradict it — pick "Monthly" up there and "Daily" here and
          the chart's own subtitle would be lying about what it plots. */}
      <div className="mb-6">
        <div className="flex items-center gap-2 mb-1">
          <Activity className="w-3.5 h-3.5 text-nature-olive" />
          <h2 className={SECTION_LABEL}>Revenue Trend</h2>
        </div>
        {hasRevenue ? (
          <p className="text-nature-muted text-xs">
            Averaging{' '}
            <span className="text-nature-olive font-medium">{formatMMK(average)}</span>
            {' '}per {UNIT[granularity] ?? 'day'}
          </p>
        ) : (
          <p className="text-nature-muted text-xs">
            Totals per {UNIT[granularity] ?? 'day'} across the selected period
          </p>
        )}
      </div>
 
      {!hasRevenue ? (
        <div className="h-[260px] flex flex-col items-center justify-center gap-1 border border-dashed border-nature-border rounded-xl">
          <p className="text-nature-muted text-sm">No revenue in this period.</p>
          <p className="text-nature-subtle text-xs">Try a wider date range, or run the batch job.</p>
        </div>
      ) : (
        <div className="h-[260px] w-full">
          <ResponsiveContainer width="100%" height="100%">
            {/* Area rather than a bare line: the filled region reads as
                accumulated money, and the gradient keeps the baseline legible
                on the low-contrast glass card. */}
            <AreaChart key={`${granularity}-${series.length}`} data={series} margin={{ top: 6, right: 8, left: 0, bottom: 0 }}>
              <defs>
                <linearGradient id="revenueFill" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor={OLIVE} stopOpacity={0.22} />
                  <stop offset="100%" stopColor={OLIVE} stopOpacity={0.01} />
                </linearGradient>
              </defs>
 
              <CartesianGrid strokeDasharray="3 4" stroke={GRID} vertical={false} />
 
              <XAxis
                dataKey="label"
                tickFormatter={(label, i) => (i % step === 0 ? formatPeriodLabel(granularity, label) : '')}
                tick={{ fontSize: 10, fill: AXIS_TEXT }}
                axisLine={{ stroke: GRID }}
                tickLine={false}
                interval={0}
                minTickGap={0}
              />
 
              {/* A labelled y-axis — the previous chart hid it, which made the
                  line's height impossible to read without hovering. */}
              <YAxis
                tickFormatter={formatCompact}
                tick={{ fontSize: 10, fill: AXIS_TEXT }}
                axisLine={false}
                tickLine={false}
                width={46}
              />
 
              <Tooltip
                content={<TrendTooltip granularity={granularity} />}
                cursor={{ stroke: OLIVE, strokeWidth: 1, strokeDasharray: '3 3' }}
              />
 
              {/* Average line turns the chart from "here's the shape" into
                  "here's which periods beat the norm". */}
              {average > 0 && (
                <ReferenceLine
                  y={average}
                  stroke={AXIS_TEXT}
                  strokeDasharray="4 4"
                  strokeOpacity={0.6}
                  label={{ value: 'avg', position: 'right', fill: AXIS_TEXT, fontSize: 9 }}
                />
              )}
 
              <Area
                type="monotone"
                dataKey="revenue"
                stroke={OLIVE}
                strokeWidth={2.25}
                fill="url(#revenueFill)"
                dot={series.length <= 31 ? { r: 2.5, fill: OLIVE, strokeWidth: 2, stroke: '#fff' } : false}
                activeDot={{ r: 5, fill: OLIVE, strokeWidth: 2, stroke: '#fff' }}
                isAnimationActive
                animationDuration={700}
                animationEasing="ease-out"
              />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      )}
    </div>
  );
}
 
 