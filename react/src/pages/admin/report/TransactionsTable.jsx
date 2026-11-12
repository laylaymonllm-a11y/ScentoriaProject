import { useEffect, useMemo, useState } from 'react';
import { ShoppingBag, Search } from 'lucide-react';
import { formatMMK } from '../../../utils/currency';
import Dropdown from '../../../components/Admin/Dropdown';
import AdminPagination from '../../../components/Admin/AdminPagination';
import SortHeader from './SortHeader';
import useTableSort from './useTableSort';
import {
  CARD, SECTION_LABEL,
  PAYMENT_STYLES, PAYMENT_FILTER_OPTIONS, paymentLabel,
  formatPeriodLabel, formatCount,
} from './constants';
 
/**
 * Completed transactions for the selected period.
 *
 * COMPLETED ONLY — the server filters on status, so there's no status column
 * and no status filter here. This is a sales report: a cancelled order isn't a
 * sale, and listing one would put a row on the page that contributed nothing to
 * any other figure on it.
 *
 * Consistency is the real reason. Revenue, orders, units, top products and the
 * scent breakdown are all revenue-recognised, because the COBOL job only
 * accumulates completed orders into them. A table listing every status
 * disagreed with every other number on the page for the same date range, and
 * its footer summed money that was never collected.
 *
 * Status-agnostic order browsing lives on the Orders page, which reads live
 * data and is the right home for it.
 *
 * Filtering here is client-side: the range already narrowed the payload
 * server-side, and a round trip per keystroke would feel broken.
 */
 
const COLUMNS = [
  { key: 'order_id', label: 'Order ID' },
  { key: 'customer_name', label: 'Customer' },
  { key: 'date', label: 'Date' },
  { key: 'item_count', label: 'Items', align: 'right', className: 'hidden sm:table-cell' },
  { key: 'total_amount', label: 'Amount', align: 'right' },
  // No longer hidden below lg — dropping the status column freed the width,
  // and payment method is the one dimension left worth filtering on.
  { key: 'payment_method', label: 'Payment' },
];
 
const ACCESSORS = {
  order_id: (r) => r.order_id,
  customer_name: (r) => r.customer_name,
  // The raw YYYYMMDD key sorts correctly as a string, so there's no need to
  // parse it into a Date just to compare.
  date: (r) => r.date,
  item_count: (r) => r.item_count,
  total_amount: (r) => r.total_amount,
  payment_method: (r) => r.payment_method || '',
};
 
export default function TransactionsTable({ transactions = [], loading }) {
  const [search, setSearch] = useState('');
  const [payment, setPayment] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
 
  const filtered = useMemo(() => {
    const needle = search.trim().toLowerCase();
 
    return transactions.filter((t) => {
      const matchesSearch =
        !needle ||
        String(t.order_id).includes(needle) ||
        (t.customer_name || '').toLowerCase().includes(needle);
 
      return matchesSearch && (!payment || t.payment_method === payment);
    });
  }, [transactions, search, payment]);
 
  const { sorted, sort, toggle } = useTableSort(filtered, ACCESSORS, {
    key: 'date',
    dir: 'desc',
  });
 
  const totals = useMemo(
    () => filtered.reduce(
      (acc, t) => ({
        orders: acc.orders + 1,
        items: acc.items + t.item_count,
        amount: acc.amount + t.total_amount,
      }),
      { orders: 0, items: 0, amount: 0 },
    ),
    [filtered],
  );
 
  const filtering = Boolean(search || payment);
 
  // Never leave someone stranded on page 4 of a now-single-page result.
  //
  // `transactions` is deliberately NOT a dependency. The page polls the API
  // every 3 minutes to follow the batch schedule, and a new array identity on
  // each poll would silently throw the reader back to page 1 mid-scroll. If a
  // refresh does shrink the data, clampedPage below already handles it.
  useEffect(() => {
    setPage(1);
  }, [search, payment, perPage]);
 
  const totalPages = Math.max(1, Math.ceil(sorted.length / perPage));
  const clampedPage = Math.min(page, totalPages);
  const visible = useMemo(
    () => sorted.slice((clampedPage - 1) * perPage, clampedPage * perPage),
    [sorted, clampedPage, perPage],
  );
 
  if (loading) {
    return <div className={`${CARD} h-[460px] animate-pulse`} />;
  }
 
  return (
    <div className={CARD}>
      {/* No `overflow-hidden` on the card: it would clip the open dropdown
          menus whenever the filtered table is short. Horizontal clipping still
          comes from the table's own scroll container below. */}
      {/* Single header row, filters right-aligned — same shape as
          TopProductsTable above. The two tables are read as a pair, so their
          headers should scan identically rather than one stacking its filters
          onto a second row. */}
      <div className="relative z-10 flex flex-wrap items-center justify-between gap-3 px-5 py-5 border-b border-nature-border/60">
        <div>
          <div className="flex items-center gap-2 mb-1">
            <ShoppingBag className="w-3.5 h-3.5 text-nature-olive" />
            <h2 className={SECTION_LABEL}>Detailed Transactions</h2>
          </div>
          <p className="text-nature-muted text-xs">
            {formatCount(filtered.length)} of {formatCount(transactions.length)} completed orders
            {filtering ? ' matching filters' : ' in this period'}
          </p>
        </div>
 
        <div className="flex flex-wrap items-center gap-2.5">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-nature-muted" />
            <input
              type="text"
              placeholder="Order ID or customer..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="bg-nature-card border border-nature-olive/20 focus:border-nature-olive/60 rounded-xl pl-9 pr-4 py-2 text-sm outline-none transition-colors w-52"
            />
          </div>
 
          <div className="w-36">
            <Dropdown value={payment} onChange={setPayment} placeholder="All Payments" options={PAYMENT_FILTER_OPTIONS} />
          </div>
        </div>
      </div>
 
      {sorted.length === 0 ? (
        <p className="text-nature-muted text-sm text-center py-16">
          {transactions.length === 0
            ? 'No transactions in this period.'
            : 'No transactions match your filters.'}
        </p>
      ) : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-nature-border/60">
                  {COLUMNS.map((col) => (
                    <SortHeader key={col.key} col={col} sort={sort} onSort={toggle} />
                  ))}
                </tr>
              </thead>
 
              <tbody>
                {visible.map((t) => (
                  <tr
                    key={t.order_id}
                    className="border-b border-nature-border/40 hover:bg-nature-olive/5 transition-colors"
                  >
                    <td className="px-5 py-4 font-medium text-neutral-800 whitespace-nowrap">
                      #{String(t.order_id).padStart(4, '0')}
                    </td>
 
                    <td className="px-5 py-4">
                      <span className="text-neutral-800 truncate block max-w-[170px]">{t.customer_name}</span>
                    </td>
 
                    <td className="px-5 py-4 text-xs text-nature-muted whitespace-nowrap">
                      {formatPeriodLabel('day', t.date, { long: true })}
                    </td>
 
                    <td className="px-5 py-4 text-right text-xs text-nature-muted tabular-nums hidden sm:table-cell">
                      {t.item_count}
                    </td>
 
                    <td className="px-5 py-4 text-right text-nature-olive font-semibold tabular-nums whitespace-nowrap">
                      {formatMMK(t.total_amount)}
                    </td>
 
                    <td className="px-5 py-4 whitespace-nowrap">
                      <span
                        className={`text-[11px] font-medium px-2.5 py-1 rounded-full ${
                          PAYMENT_STYLES[t.payment_method] || 'bg-gray-100 text-gray-600'
                        }`}
                      >
                        {paymentLabel(t.payment_method)}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
 
              {/* Totals span every filtered row, not the visible page. Since
                  the rows are completed orders only, this amount IS revenue for
                  the period — filter to Cash and it's your till reconciliation.
                  Six cells, matching COLUMNS. */}
              <tfoot>
                <tr className="bg-nature-olive/5 border-t border-nature-border/60">
                  <td className="px-5 py-3.5 text-[10px] font-semibold tracking-[0.15em] uppercase text-nature-olive whitespace-nowrap">
                    {filtering ? 'Filtered' : 'Total'}
                  </td>
                  <td className="px-5 py-3.5 text-xs text-nature-muted whitespace-nowrap">
                    {formatCount(totals.orders)} {totals.orders === 1 ? 'order' : 'orders'}
                  </td>
                  <td className="px-5 py-3.5" />
                  <td className="px-5 py-3.5 text-right tabular-nums font-semibold text-neutral-800 hidden sm:table-cell">
                    {formatCount(totals.items)}
                  </td>
                  <td className="px-5 py-3.5 text-right tabular-nums font-semibold text-nature-olive whitespace-nowrap">
                    {formatMMK(totals.amount)}
                  </td>
                  <td className="px-5 py-3.5" />
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
 
 