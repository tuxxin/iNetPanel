/**
 * TableKit — Lightweight table sorting & filtering for iNetPanel
 *
 * Usage:
 *   TableKit.init('my-table', { filter: true });
 *
 * - Adds click-to-sort on <th> headers (asc/desc toggle)
 * - Optionally inserts a filter/search input above the table
 * - Skips columns with class "no-sort"
 * - Handles text, numbers, dates, and file sizes (KB/MB/GB)
 */
const TableKit = (() => {

    function parseValue(text) {
        const t = text.trim();
        // File sizes: "3.9 MB", "512 KB", "1.2 GB"
        const sizeMatch = t.match(/^([\d.]+)\s*(B|KB|MB|GB)$/i);
        if (sizeMatch) {
            const n = parseFloat(sizeMatch[1]);
            const u = sizeMatch[2].toUpperCase();
            const mult = { B: 1, KB: 1024, MB: 1048576, GB: 1073741824 };
            return n * (mult[u] || 1);
        }
        // Date: "2026-03-08" or "2026-03-08 12:34:56"
        if (/^\d{4}-\d{2}-\d{2}/.test(t)) return new Date(t).getTime() || 0;
        // Number
        const num = parseFloat(t);
        if (!isNaN(num) && /^[\d.\-]+$/.test(t)) return num;
        // Text (lowercase for comparison)
        return t.toLowerCase();
    }

    function sortTable(table, colIdx, asc) {
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        // Don't sort placeholder rows (Loading…, No data, etc.)
        if (rows.length <= 1 && rows[0]?.querySelectorAll('td').length <= 1) return;

        rows.sort((a, b) => {
            const aCell = a.cells[colIdx];
            const bCell = b.cells[colIdx];
            if (!aCell || !bCell) return 0;
            const aVal = parseValue(aCell.textContent);
            const bVal = parseValue(bCell.textContent);
            if (typeof aVal === 'number' && typeof bVal === 'number') {
                return asc ? aVal - bVal : bVal - aVal;
            }
            const aStr = String(aVal), bStr = String(bVal);
            return asc ? aStr.localeCompare(bStr) : bStr.localeCompare(aStr);
        });

        rows.forEach(r => tbody.appendChild(r));
    }

    function init(tableId, opts = {}) {
        const table = document.getElementById(tableId);
        if (!table) return;

        const headers = table.querySelectorAll('thead th');
        let currentCol = -1;
        let currentAsc = true;

        // Add sort indicators and click handlers
        headers.forEach((th, idx) => {
            if (th.classList.contains('no-sort')) return;
            th.style.cursor = 'pointer';
            th.style.userSelect = 'none';
            const arrow = document.createElement('span');
            arrow.className = 'tk-arrow';
            arrow.style.cssText = 'margin-left:4px;font-size:0.7em;opacity:0.3';
            arrow.textContent = '\u2195';
            th.appendChild(arrow);

            th.addEventListener('click', () => {
                if (currentCol === idx) {
                    currentAsc = !currentAsc;
                } else {
                    currentCol = idx;
                    currentAsc = true;
                }
                // Update arrows
                headers.forEach(h => {
                    const a = h.querySelector('.tk-arrow');
                    if (a) { a.textContent = '\u2195'; a.style.opacity = '0.3'; }
                });
                arrow.textContent = currentAsc ? '\u2191' : '\u2193';
                arrow.style.opacity = '1';
                sortTable(table, idx, currentAsc);
            });
        });

        // Filter input
        if (opts.filter) {
            // Check if a filter input already exists (e.g. pkg-search)
            if (opts.filterInput) {
                const existing = document.getElementById(opts.filterInput);
                if (existing) {
                    existing.addEventListener('input', () => filterRows(table, existing.value));
                    return;
                }
            }
            const wrap = document.createElement('div');
            wrap.className = 'px-3 py-2 bg-white border-bottom';
            const input = document.createElement('input');
            input.type = 'search';
            input.setAttribute('autocomplete', 'one-time-code');
            input.setAttribute('readonly', '');
            input.setAttribute('data-1p-ignore', '');
            input.setAttribute('data-lpignore', 'true');
            input.setAttribute('data-form-type', 'other');
            input.name = 'tablekit-filter-' + table.id;
            input.className = 'form-control form-control-sm';
            input.placeholder = 'Filter\u2026';
            input.style.maxWidth = '260px';
            input.addEventListener('focus', function() { this.removeAttribute('readonly'); });
            wrap.appendChild(input);
            // Insert before table's card-body or before the table
            const cardBody = table.closest('.card-body');
            if (cardBody) {
                cardBody.parentNode.insertBefore(wrap, cardBody);
            } else {
                table.parentNode.insertBefore(wrap, table);
            }
            input.addEventListener('input', () => filterRows(table, input.value));
        }
    }

    function filterRows(table, query) {
        const q = query.toLowerCase();
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            // Skip placeholder rows
            if (row.cells.length <= 1) return;
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(q) ? '' : 'none';
        });
    }

    return { init };
})();
