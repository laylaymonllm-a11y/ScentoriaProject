import { useEffect, useRef, useState } from 'react';
// Only feather-derived icon names here — those have been stable across every
// lucide-react release, unlike later additions such as FileSpreadsheet.
import { ChevronDown, Download, File, FileText, Loader2 } from 'lucide-react';
import api from '../../../api';
 
/**
 * Export dropdown — PDF or Excel/CSV.
 *
 * Purpose-built rather than reusing components/Admin/Dropdown, because this is
 * a menu of ACTIONS, not a value selector. Dropdown keeps a selected value and
 * displays it as its label; "Export" must always read "Export", since picking
 * PDF once shouldn't leave the control claiming PDF is the current state. The
 * visual language is copied from Dropdown so it pairs with the period control
 * beside it.
 *
 * Both formats hit the same Laravel endpoint that renders the on-screen report,
 * so a download can never disagree with what was being looked at. The request
 * goes through the shared axios instance rather than a plain <a href> because
 * the API is token-authenticated — a bare link would arrive without the
 * Authorization header and 401.
 */
 
const FORMATS = [
  { value: 'pdf', label: 'PDF', icon: FileText, hint: 'Formatted document' },
  { value: 'csv', label: 'Excel / CSV', icon: File, hint: 'Opens in Excel' },
];
 
export default function ExportMenu({ from, to, granularity, disabled }) {
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(null); // 'pdf' | 'csv' | null
  const wrapperRef = useRef(null);
 
  // Close on outside click and on Escape — same behaviour as Dropdown, so the
  // two controls in the header dismiss identically.
  useEffect(() => {
    function handleClickOutside(e) {
      if (wrapperRef.current && !wrapperRef.current.contains(e.target)) setOpen(false);
    }
    function handleEscape(e) {
      if (e.key === 'Escape') setOpen(false);
    }
 
    document.addEventListener('mousedown', handleClickOutside);
    document.addEventListener('keydown', handleEscape);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleEscape);
    };
  }, []);
 
  async function download(format) {
    setOpen(false);
    setBusy(format);
 
    try {
      const res = await api.get('/admin/sales-report/export', {
        params: { format, from, to, granularity },
        responseType: 'blob',
      });
 
      // The PDF endpoint falls back to printable HTML when dompdf isn't
      // installed on the server. Detect that from the blob's MIME type rather
      // than a custom X-Pdf-Fallback header: the API is a different origin to
      // the dev server, and custom headers aren't readable cross-origin
      // without Access-Control-Expose-Headers. Content-Type is CORS-safelisted,
      // so blob.type is always populated — and it describes what the bytes
      // actually are, which is the thing worth branching on.
      const isHtml = (res.data?.type || '').includes('text/html');
 
      if (format === 'pdf' && isHtml) {
        // Open for the browser's own "Save as PDF" instead of writing HTML to
        // disk under a .pdf extension, which produces Chrome's "Failed to load
        // PDF document".
        const url = URL.createObjectURL(res.data);
        window.open(url, '_blank', 'noopener');
        setTimeout(() => URL.revokeObjectURL(url), 60_000);
        return;
      }
 
      const url = URL.createObjectURL(res.data);
      const link = document.createElement('a');
      link.href = url;
      link.download = `scentoria-sales-report_${from}_${to}.${format === 'pdf' ? 'pdf' : 'csv'}`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch {
      // The axios response interceptor already surfaces a toast.
    } finally {
      setBusy(null);
    }
  }
 
  const running = busy !== null;
 
  return (
    <div ref={wrapperRef} className="relative shrink-0">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        disabled={disabled || running}
        aria-haspopup="menu"
        aria-expanded={open}
        className="flex items-center gap-1.5 bg-nature-bg border border-nature-border rounded-lg px-3 py-2 text-sm text-nature-dark outline-none cursor-pointer whitespace-nowrap transition-colors hover:border-nature-olive/40 disabled:opacity-50 disabled:cursor-not-allowed"
      >
        {running ? (
          <Loader2 className="w-3.5 h-3.5 animate-spin text-nature-olive" />
        ) : (
          <Download className="w-3.5 h-3.5 text-nature-muted" />
        )}
        {running ? 'Exporting...' : 'Export'}
        <ChevronDown
          className={`w-4 h-4 text-nature-muted flex-shrink-0 transition-transform duration-150 ${open ? 'rotate-180' : ''}`}
        />
      </button>
 
      {open && (
        <div
          role="menu"
          className="absolute right-0 z-30 mt-1 min-w-[190px] bg-nature-card border border-nature-border rounded-lg shadow-lg py-1"
        >
          {FORMATS.map(({ value, label, icon: Icon, hint }) => (
            <button
              key={value}
              type="button"
              role="menuitem"
              onClick={() => download(value)}
              className="w-full flex items-center gap-2.5 text-left px-3 py-2 hover:bg-nature-sage/20 transition-colors"
            >
              <Icon className="w-3.5 h-3.5 text-nature-olive shrink-0" />
              <span className="flex-1 min-w-0">
                <span className="block text-sm text-nature-dark">{label}</span>
                <span className="block text-[10px] text-nature-subtle">{hint}</span>
              </span>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
 
 