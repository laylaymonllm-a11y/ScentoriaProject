import { useMemo, useState } from 'react';
 
/**
 * Click-to-sort for the report's data tables.
 *
 * Both tables sort client-side. The date range has already narrowed the data
 * server-side, so the payload is small and sorting in the browser is instant —
 * a round trip per column click would feel broken by comparison.
 *
 * `accessors` maps a column key to a function returning the comparable value.
 * Strings are compared case-insensitively; everything else falls through to
 * plain `<` / `>`, which handles the numeric and YYYYMMDD-string columns.
 *
 * @param rows      the (already filtered) rows to sort
 * @param accessors { [columnKey]: (row) => comparable }
 * @param initial   { key, dir } — dir is 'asc' | 'desc'
 */
export default function useTableSort(rows, accessors, initial) {
  const [sort, setSort] = useState(initial);
 
  function toggle(key) {
    setSort((prev) =>
      prev.key === key
        // Second click on the same column flips direction.
        ? { key, dir: prev.dir === 'asc' ? 'desc' : 'asc' }
        // A new column starts descending for numbers and ascending for text:
        // clicking "Revenue" almost always means "show me the biggest",
        // whereas clicking "Product" means "alphabetise".
        : { key, dir: guessInitialDir(rows, accessors, key) },
    );
  }
 
  const sorted = useMemo(() => {
    const accessor = accessors[sort.key];
    if (!accessor) return rows;
 
    const factor = sort.dir === 'asc' ? 1 : -1;
 
    // Copy first — Array.prototype.sort mutates, and these rows come straight
    // from state.
    return [...rows].sort((a, b) => {
      const av = normalise(accessor(a));
      const bv = normalise(accessor(b));
 
      if (av < bv) return -1 * factor;
      if (av > bv) return 1 * factor;
      return 0;
    });
  }, [rows, accessors, sort]);
 
  return { sorted, sort, toggle };
}
 
function normalise(value) {
  if (typeof value === 'string') return value.toLowerCase();
  if (value === null || value === undefined) return '';
  return value;
}
 
function guessInitialDir(rows, accessors, key) {
  const accessor = accessors[key];
  if (!accessor || rows.length === 0) return 'asc';
  return typeof accessor(rows[0]) === 'number' ? 'desc' : 'asc';
}
 
 