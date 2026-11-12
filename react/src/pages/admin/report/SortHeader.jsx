import { ArrowUpDown, ArrowUp, ArrowDown } from 'lucide-react';
 
/**
 * Sortable table header cell.
 *
 * Deliberately the same affordance as pages/admin/orders/OrderTable.jsx — a
 * faint double arrow that appears on hover, replaced by a solid directional
 * arrow once the column is active. Sorting behaves the same everywhere in the
 * admin, so nobody has to relearn it per table.
 *
 * @param col   { key, label, align?: 'left' | 'right', className? }
 * @param sort  { key, dir } from useTableSort
 */
export default function SortHeader({ col, sort, onSort }) {
  const active = sort.key === col.key;
  const right = col.align === 'right';
 
  return (
    <th
      onClick={() => onSort(col.key)}
      aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
      className={`text-[10px] font-semibold tracking-[0.15em] uppercase px-5 py-4 cursor-pointer select-none group whitespace-nowrap ${
        right ? 'text-right' : 'text-left'
      } ${col.className || ''}`}
    >
      <span
        className={`inline-flex items-center gap-1 ${right ? 'flex-row-reverse' : ''} ${
          active ? 'text-nature-olive' : 'text-nature-muted group-hover:text-neutral-700'
        }`}
      >
        {col.label}
        {active ? (
          sort.dir === 'asc' ? <ArrowUp className="w-3 h-3" /> : <ArrowDown className="w-3 h-3" />
        ) : (
          <ArrowUpDown className="w-3 h-3 opacity-0 group-hover:opacity-40 transition-opacity" />
        )}
      </span>
    </th>
  );
}
 
 