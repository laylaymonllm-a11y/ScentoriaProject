import {
  BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid, Cell, LabelList,
} from 'recharts';
import { Droplet } from 'lucide-react';
import { formatMMK } from '../../../utils/currency';
import {
  CARD, SECTION_LABEL, GRID, AXIS_TEXT, SCENT_RAMP,
  formatCompact, formatCount,
} from './constants';
 
function ScentTooltip({ active, payload }) {
  if (!active || !payload?.length) return null;
  const row = payload[0].payload;
 
  return (
    <div className="bg-white/95 backdrop-blur-xl border border-nature-border/70 rounded-xl px-3.5 py-2.5 shadow-xl">
      <p className="text-xs font-semibold text-neutral-800 mb-1.5">{row.scent_name}</p>
      <p className="text-sm font-semibold text-nature-olive mb-1">{formatMMK(row.revenue)}</p>
      <div className="flex items-center gap-3 text-[11px] text-nature-muted border-t border-nature-border/50 pt-1.5">
        <span>{formatCount(row.units)} units</span>
        <span className="w-px h-3 bg-nature-border" />
        <span>{row.share}% of sales</span>
      </div>
    </div>
  );
}
 
/**
 * Revenue by scent profile (Floral, Woody, Citrus, Oriental, ...).
 *
 * Horizontal bars rather than a pie: profile names are words, not codes, and
 * a horizontal axis gives them room to sit beside their bar instead of in a
 * legend the eye has to cross-reference. Bars also stay comparable when two
 * profiles are within a couple of percent, where pie slices become guesswork.
 */
export default function ScentProfileChart({ breakdown = [], loading }) {
  if (loading) {
    return <div className={`${CARD} h-[360px] animate-pulse`} />;
  }
 
  // Cap the plotted rows so one long tail of niche profiles can't squeeze the
  // bars into hairlines. The card footer still reports the full count.
  const data = breakdown.slice(0, 8);
  const hidden = breakdown.length - data.length;
  const leader = breakdown[0];
 
  // ~34px per bar keeps the row height constant whether there are 3 profiles
  // or 8, instead of letting recharts stretch 3 bars to fill 260px.
  const chartHeight = Math.max(data.length * 34 + 16, 120);
 
  return (
    <div className={`${CARD} p-6 flex flex-col`}>
      <div className="flex items-start justify-between mb-6">
        <div>
          <div className="flex items-center gap-2 mb-1">
            <Droplet className="w-3.5 h-3.5 text-nature-olive" />
            <h2 className={SECTION_LABEL}>Sales by Scent Profile</h2>
          </div>
          <p className="text-nature-muted text-xs">Revenue share across fragrance families</p>
        </div>
      </div>
 
      {data.length === 0 ? (
        <div className="flex-1 min-h-[260px] flex flex-col items-center justify-center gap-1 border border-dashed border-nature-border rounded-xl">
          <p className="text-nature-muted text-sm">No scent-profile sales yet.</p>
          <p className="text-nature-subtle text-xs">Nothing sold in this period.</p>
        </div>
      ) : (
        <>
          <div style={{ height: chartHeight }} className="w-full -ml-1">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart
                data={data}
                layout="vertical"
                margin={{ top: 0, right: 44, left: 0, bottom: 0 }}
                barCategoryGap="22%"
              >
                <CartesianGrid strokeDasharray="3 4" stroke={GRID} horizontal={false} />
 
                <XAxis
                  type="number"
                  tickFormatter={formatCompact}
                  tick={{ fontSize: 10, fill: AXIS_TEXT }}
                  axisLine={false}
                  tickLine={false}
                />
 
                <YAxis
                  type="category"
                  dataKey="scent_name"
                  tick={{ fontSize: 11, fill: AXIS_TEXT }}
                  axisLine={false}
                  tickLine={false}
                  width={84}
                />
 
                <Tooltip content={<ScentTooltip />} cursor={{ fill: 'rgba(74,104,56,0.05)' }} />
 
                <Bar dataKey="revenue" radius={[0, 5, 5, 0]} isAnimationActive animationDuration={650}>
                  {data.map((row, i) => (
                    <Cell key={row.scent_name} fill={SCENT_RAMP[i % SCENT_RAMP.length]} />
                  ))}
                  {/* Share printed at the bar end so the chart answers "what
                      proportion?" without a hover. */}
                  <LabelList
                    dataKey="share"
                    position="right"
                    formatter={(v) => `${v}%`}
                    style={{ fill: AXIS_TEXT, fontSize: 10, fontWeight: 500 }}
                  />
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          </div>
 
          <div className="mt-auto pt-5 border-t border-nature-border/50 flex items-baseline justify-between gap-3">
            <div className="min-w-0">
              <p className="text-[10px] text-nature-muted tracking-wide uppercase">Leading profile</p>
              <p className="text-sm font-medium text-neutral-800 truncate">{leader.scent_name}</p>
            </div>
            <p className="text-sm font-semibold text-nature-olive tabular-nums shrink-0">{leader.share}%</p>
          </div>
 
          {hidden > 0 && (
            <p className="text-[10px] text-nature-subtle mt-2">
              +{hidden} smaller {hidden === 1 ? 'profile' : 'profiles'} not charted — all included in the export.
            </p>
          )}
        </>
      )}
    </div>
  );
}
 
 