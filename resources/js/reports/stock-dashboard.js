document.querySelectorAll('[data-sortable-table]').forEach((table) => {
    const body = table.tBodies[0];

    if (!body) {
        return;
    }

    table.querySelectorAll('[data-sort-index]').forEach((button) => {
        button.addEventListener('click', () => {
            const header = button.closest('th');
            const index = Number(button.dataset.sortIndex);
            const type = button.dataset.sortType || 'text';
            const direction = header.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
            const multiplier = direction === 'ascending' ? 1 : -1;
            const collator = new Intl.Collator('es', { numeric: true, sensitivity: 'base' });

            table.querySelectorAll('th[aria-sort]').forEach((item) => item.setAttribute('aria-sort', 'none'));
            table.querySelectorAll('[data-sort-index] span').forEach((indicator) => {
                indicator.textContent = '↕';
            });

            header.setAttribute('aria-sort', direction);
            button.querySelector('span').textContent = direction === 'ascending' ? '↑' : '↓';

            const rows = Array.from(body.rows);
            rows.sort((leftRow, rightRow) => {
                const commercialDifference = Number(rightRow.dataset.commercial || 0) - Number(leftRow.dataset.commercial || 0);

                if (commercialDifference !== 0) {
                    return commercialDifference;
                }

                const left = leftRow.cells[index]?.dataset.sortValue ?? leftRow.cells[index]?.textContent.trim() ?? '';
                const right = rightRow.cells[index]?.dataset.sortValue ?? rightRow.cells[index]?.textContent.trim() ?? '';

                if (type === 'number') {
                    const leftNumber = left === '' ? null : Number(left);
                    const rightNumber = right === '' ? null : Number(right);

                    if (leftNumber === null && rightNumber === null) {
                        return 0;
                    }
                    if (leftNumber === null) {
                        return 1;
                    }
                    if (rightNumber === null) {
                        return -1;
                    }

                    return (leftNumber - rightNumber) * multiplier;
                }

                return collator.compare(left, right) * multiplier;
            });

            rows.forEach((row) => body.appendChild(row));
        });
    });
});

document.querySelectorAll('[data-stock-scroll-region]').forEach((region) => {
    const top = region.querySelector('[data-stock-scroll-top]');
    const spacer = region.querySelector('[data-stock-scroll-spacer]');
    const body = region.querySelector('[data-stock-scroll-body]');
    const table = body?.querySelector('table');

    if (!top || !spacer || !body || !table) {
        return;
    }

    let syncing = false;

    const updateWidth = () => {
        spacer.style.width = `${table.scrollWidth}px`;
        top.hidden = table.scrollWidth <= body.clientWidth;
        top.scrollLeft = body.scrollLeft;
    };

    top.addEventListener('scroll', () => {
        if (syncing) return;
        syncing = true;
        body.scrollLeft = top.scrollLeft;
        syncing = false;
    });

    body.addEventListener('scroll', () => {
        if (syncing) return;
        syncing = true;
        top.scrollLeft = body.scrollLeft;
        syncing = false;
    });

    if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(updateWidth);
        observer.observe(region);
        observer.observe(table);
    }

    requestAnimationFrame(updateWidth);
});

const dateFrom = document.querySelector('#stock_date_from');
const dateTo = document.querySelector('#stock_date_to');

function localDateString(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

document.querySelectorAll('[data-period-days]').forEach((button) => {
    button.addEventListener('click', () => {
        if (!dateFrom || !dateTo) return;
        const end = new Date();
        const start = new Date();
        start.setDate(end.getDate() - Number(button.dataset.periodDays) + 1);
        dateFrom.value = localDateString(start);
        dateTo.value = localDateString(end);
    });
});

document.querySelector('[data-current-month]')?.addEventListener('click', () => {
    if (!dateFrom || !dateTo) return;
    const today = new Date();
    dateFrom.value = localDateString(new Date(today.getFullYear(), today.getMonth(), 1));
    dateTo.value = localDateString(today);
});
