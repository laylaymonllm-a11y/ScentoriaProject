import { useEffect, useMemo, useState } from 'react';
import { Package, Search } from 'lucide-react';
import { formatMMK } from '../../../utils/currency';
import Dropdown from '../../../components/Admin/Dropdown';
import AdminPagination from '../../../components/Admin/AdminPagination';
import SortHeader from './SortHeader';
import useTableSort from './useTableSort';
import { CARD, SECTION_LABEL, formatCount } from './constants';
 
/**
 * Product sales performance for the selected date range.
 *
 * Every column is sortable and the footer totals recompute on whatever is
 * currently filtered — so "how much did Dior contribute this month" is two
 * clicks, not an export into Excel. Filtering happens client-side because the
 * date range already narrowed the payload server-side; a round trip per
 * keystroke would feel broken.
 *
 * Share is NOT recomputed on the filtered set, deliberately. It comes from the
 * server as each product's slice of total range revenue, and that's what
 * "contribution" means on a sales report. Renormalising it to the filter would
 * make a single filtered brand read as 100% of the business.
 */
 
// align: 'right' is used for every measure so the digits line up in a column
// you can scan; text columns stay left.
const COLUMNS = [
  { key: 'product_name', label: 'Product Name' },
  { key: 'brand_name', label: 'Brand', className: 'hidden md:table-cell' },
  { key: 'units_sold', label: 'Units Sold', align: 'right' },
  { key: 'avg_price', label: 'Avg. Unit Price', align: 'right', className: 'hidden lg:table-cell' },
  { key: 'revenue', label: 'Total Revenue', align: 'right' },
  { key: 'share', label: 'Share', align: 'right' },
];
 
const ACCESSORS = {
  product_name: (r) => r.product_name,
  brand_name: (r) => r.brand_name,
  units_sold: (r) => r.units_sold,
  avg_price: (r) => r.avg_price,
  revenue: (r) => r.revenue,
  share: (r) => r.share,
};
 
export default function TopProductsTable({ products = [], loading }) {
  const [search, setSearch] = useState('');
  const [brand, setBrand] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
 
  // Average unit price is derived here rather than on the server — it's a pure
  // function of two columns already in the payload, and deriving it client-side
  // keeps it sortable without another API field.
  const rows = useMemo(
    () => products.map((p) => ({
      ...p,
      avg_price: p.units_sold > 0 ? Math.round(p.revenue / p.units_sold) : 0,
    })),
    [products],
  );
 
  // Brand options come from the data, not a fetch — only brands that actually
  // sold in this range are worth offering.
  const brandOptions = useMemo(() => {
    const names = [...new Set(rows.map((r) => r.brand_name).filter((b) => b && b !== '—'))].sort();
    return [{ value: '', label: 'All Brands' }, ...names.map((b) => ({ value: b, label: b }))];
  }, [rows]);
 
  const filtered = useMemo(() => {
    const needle = search.trim().toLowerCase();
 
    return rows.filter((r) => {
      const matchesSearch =
        !needle ||
        r.product_name.toLowerCase().includes(needle) ||
        (r.brand_name || '').toLowerCase().includes(needle);
 
      return matchesSearch && (!brand || r.brand_name === brand);
    });
  }, [rows, search, brand]);
 
  const { sorted, sort, toggle } = useTableSort(filtered, ACCESSORS, {
    key: 'units_sold',
    dir: 'desc',
  });
 
  // Totals over the filtered set — the whole point of the footer.
  const totals = useMemo(
    () => filtered.reduce(
      (acc, r) => ({
        units: acc.units + r.units_sold,
        revenue: acc.revenue + r.revenue,
        share: acc.share + r.share,
      }),
      { units: 0, revenue: 0, share: 0 },
    ),
    [filtered],
  );
 
  // `products` is deliberately not a dependency — the page polls every 3
  // minutes to follow the batch schedule, and resetting on each new array
  // identity would throw the reader back to page 1 mid-scroll. clampedPage
  // below already covers the case where a refresh shrinks the data.
  useEffect(() => {
    setPage(1);
  }, [search, brand, perPage]);
 
  const totalPages = Math.max(1, Math.ceil(sorted.length / perPage));
  const clampedPage = Math.min(page, totalPages);
  const visible = useMemo(
    () => sorted.slice((clampedPage - 1) * perPage, clampedPage * perPage),
    [sorted, clampedPage, perPage],
  );
 
  if (loading) {
    return <div className={`${CARD} h-[460px] animate-pulse`} />;
  }
 
  const filtering = Boolean(search || brand);
 
  return (
    <div className={CARD}>
      <div className="relative z-10 flex flex-wrap items-center justify-between gap-3 px-5 py-5 border-b border-nature-border/60">
        <div>
          <div className="flex items-center gap-2 mb-1">
            <Package className="w-3.5 h-3.5 text-nature-olive" />
            <h2 className={SECTION_LABEL}>Product Sales Performance</h2>
          </div>
          <p className="text-nature-muted text-xs">
            {formatCount(filtered.length)} of {formatCount(rows.length)} products
            {filtering ? ' matching filters' : ' sold in this period'}
          </p>
        </div>
 
        <div className="flex flex-wrap items-center gap-2.5">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-nature-muted" />
            <input
              type="text"
              placeholder="Product or brand..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="bg-nature-card border border-nature-olive/20 focus:border-nature-olive/60 rounded-xl pl-9 pr-4 py-2 text-sm outline-none transition-colors w-52"
            />
          </div>
          <div className="w-44">
            <Dropdown value={brand} onChange={setBrand} placeholder="All Brands" options={brandOptions} />
          </div>
        </div>
      </div>
 
      {sorted.length === 0 ? (
        <p className="text-nature-muted text-sm text-center py-16">
          {rows.length === 0
            ? 'No products sold in this period.'
            : 'No products match your filters.'}
        </p>
      ) : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-nature-border/60">
                  <th className="w-12 px-5 py-4 text-left text-[10px] font-semibold tracking-[0.15em] uppercase text-nature-muted">#</th>
                  {COLUMNS.map((col) => (
                    <SortHeader key={col.key} col={col} sort={sort} onSort={toggle} />
                  ))}
                </tr>
              </thead>
 
              <tbody>
                {visible.map((p) => (
                  <tr
                    key={p.product_name}
                    className="border-b border-nature-border/40 hover:bg-nature-olive/5 transition-colors"
                  >
                    {/* Rank is the server's, from units sold across the whole
                        range — it stays put when you sort by another column so
                        you can still see where a product actually places. */}
                    <td className="px-5 py-4">
                      <span className={p.rank <= 3 ? 'font-serif text-base text-nature-olive' : 'text-xs text-nature-subtle'}>
                        {p.rank}
                      </span>
                    </td>
 
                    <td className="px-5 py-4">
                      <p className="font-medium text-neutral-800 truncate max-w-[260px]">{p.product_name}</p>
                      <p className="text-[11px] text-nature-subtle md:hidden">{p.brand_name}</p>
                    </td>
 
                    <td className="px-5 py-4 text-xs text-nature-muted hidden md:table-cell">
                      <span className="truncate block max-w-[150px]">{p.brand_name}</span>
                    </td>
 
                    <td className="px-5 py-4 text-right tabular-nums text-neutral-800">
                      {formatCount(p.units_sold)}
                    </td>
 
                    <td className="px-5 py-4 text-right tabular-nums text-xs text-nature-muted whitespace-nowrap hidden lg:table-cell">
                      {formatMMK(p.avg_price)}
                    </td>
 
                    <td className="px-5 py-4 text-right text-nature-olive font-semibold tabular-nums whitespace-nowrap">
                      {formatMMK(p.revenue)}
                    </td>
 
                    <td className="px-5 py-4">
                      <div className="flex items-center justify-end gap-2.5">
                        <div className="w-14 h-1.5 bg-nature-border/40 rounded-full overflow-hidden shrink-0">
                          <div
                            className="h-full bg-nature-olive rounded-full"
                            style={{ width: `${Math.min(100, p.share)}%` }}
                          />
                        </div>
                        <span className="text-[11px] text-nature-muted tabular-nums w-10 text-right shrink-0">
                          {p.share}%
                        </span>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
 
              {/* Totals reflect every filtered row, not just the visible page —
                  a per-page subtotal would be a number nobody asked for. */}
              <tfoot>
                <tr className="bg-nature-olive/5 border-t border-nature-border/60">
                  <td className="px-5 py-3.5" />
                  <td className="px-5 py-3.5 text-[10px] font-semibold tracking-[0.15em] uppercase text-nature-olive">
                    {filtering ? 'Filtered total' : 'Total'}
                  </td>
                  <td className="px-5 py-3.5 hidden md:table-cell" />
                  <td className="px-5 py-3.5 text-right tabular-nums font-semibold text-neutral-800">
                    {formatCount(totals.units)}
                  </td>
                  <td className="px-5 py-3.5 hidden lg:table-cell" />
                  <td className="px-5 py-3.5 text-right tabular-nums font-semibold text-nature-olive whitespace-nowrap">
                    {formatMMK(totals.revenue)}
                  </td>
                  <td className="px-5 py-3.5 text-right text-[11px] tabular-nums text-nature-muted">
                    {totals.share.toFixed(1)}%
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
 
          <AdminPagination
            page={clampedPage}
            totalPages={totalPages}
            onPageChange={setPage}
            perPage={perPage}
            onPerPageChange={setPerPage}
            totalItems={sorted.length}
          />
        </>
      )}
    </div>
  );
}
 
 