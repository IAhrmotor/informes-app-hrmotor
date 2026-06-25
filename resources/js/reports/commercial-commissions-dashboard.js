const tableSortState = new Map();
const commissionSummaryColumnsStorageKey = 'commissionSummaryColumns';
let syncCommissionSummaryScrollbar = () => {};
const commissionSummaryColumnDefinitions = [
    { key: 'commercial_name', label: 'Comercial', alwaysVisible: true },
    { key: 'final_commission', label: 'Comision final', alwaysVisible: true },
    { key: 'deliveries_count', label: 'Entregas', alwaysVisible: true },
    { key: 'sales_amount', label: 'Ventas', alwaysVisible: true },
    { key: 'purchases_amount', label: 'Compras', alwaysVisible: true },
    { key: 'shared_amount', label: 'Compartidas', alwaysVisible: true },
    { key: 'discount_penalty_amount', label: 'Descuento 5%' },
    { key: 'stock_150_amount', label: 'Stock +150' },
    { key: 'bonus_15_amount', label: 'Bonus +15' },
    { key: 'prima_total', label: 'Prima total' },
    { key: 'delivery_bracket', label: 'Tramo' },
    { key: 'prima_adjusted', label: 'Prima ajustada', alwaysVisible: true },
    { key: 'reviews_penalty', label: 'Pen. resenas', alwaysVisible: true },
    { key: 'reviews_percentage', label: '% resenas' },
    { key: 'financing_penalty', label: 'Pen. financiacion', alwaysVisible: true },
    { key: 'financing_percentage', label: '% financiacion' },
    { key: 'total_penalties', label: 'Penalizaciones', alwaysVisible: true },
    { key: 'financing_product_amount', label: 'Prod. financiacion' },
    { key: 'guarantee_product_amount', label: 'Prod. garantias' },
];
let commissionSummaryVisibleColumns = loadVisibleColumns(
    commissionSummaryColumnsStorageKey,
    commissionSummaryColumnDefinitions
);

document.addEventListener('DOMContentLoaded', () => {
    bindCommissionTabs();
    bindCommissionCommercialPicker();
    bindCommissionDetailTabs();
    bindSortableTables();
    initCommissionSummaryColumns();
    initCommissionSummaryHorizontalScroll();
    bindKpiTooltips();
});

function bindCommissionTabs() {
    const triggers = [...document.querySelectorAll('[data-commission-tab-trigger]')];
    const panels = [...document.querySelectorAll('[data-commission-tab-panel]')];

    if (!triggers.length || !panels.length) {
        return;
    }

    const activate = (targetTab) => {
        triggers.forEach((trigger) => {
            trigger.classList.toggle('active', trigger.dataset.commissionTabTrigger === targetTab);
        });

        panels.forEach((panel) => {
            panel.classList.toggle('is-hidden', panel.dataset.commissionTabPanel !== targetTab);
        });
    };

    triggers.forEach((trigger) => {
        trigger.addEventListener('click', () => activate(trigger.dataset.commissionTabTrigger));
    });
}

function bindCommissionCommercialPicker() {
    const searchInput = document.getElementById('commissionCommercialSearch');
    const emptyState = document.getElementById('commissionCommercialEmpty');
    const options = [...document.querySelectorAll('[data-commercial-option]')];
    const panels = [...document.querySelectorAll('[data-commercial-panel]')];

    if (!options.length || !panels.length) {
        return;
    }

    const visibleOptions = () => options.filter((option) => !option.classList.contains('is-hidden'));

    const activateCommercial = (commercialId) => {
        let matched = false;

        options.forEach((option) => {
            const isActive = option.dataset.commercialId === commercialId && !option.classList.contains('is-hidden');
            option.classList.toggle('is-active', isActive);
            matched = matched || isActive;
        });

        panels.forEach((panel) => {
            const isActive = panel.dataset.commercialId === commercialId && matched;
            panel.classList.toggle('is-hidden', !isActive);
            panel.classList.toggle('is-active', isActive);
        });

        if (!matched) {
            panels.forEach((panel) => panel.classList.add('is-hidden'));
        }
    };

    const filterOptions = () => {
        const term = (searchInput?.value || '').trim().toLowerCase();

        options.forEach((option) => {
            const haystack = option.dataset.commercialSearch || '';
            option.classList.toggle('is-hidden', term !== '' && !haystack.includes(term));
        });

        const firstVisible = visibleOptions()[0];
        emptyState?.classList.toggle('is-hidden', Boolean(firstVisible));

        if (firstVisible) {
            activateCommercial(firstVisible.dataset.commercialId);
        } else {
            activateCommercial('');
        }
    };

    options.forEach((option) => {
        option.addEventListener('click', () => {
            activateCommercial(option.dataset.commercialId);
        });
    });

    searchInput?.addEventListener('input', filterOptions);
    filterOptions();
}

function bindCommissionDetailTabs() {
    const commercialPanels = [...document.querySelectorAll('[data-commercial-panel]')];

    commercialPanels.forEach((panel) => {
        const triggers = [...panel.querySelectorAll('[data-commercial-detail-tab-trigger]')];
        const detailPanels = [...panel.querySelectorAll('[data-commercial-detail-tab-panel]')];

        if (!triggers.length || !detailPanels.length) {
            return;
        }

        const activate = (targetTab) => {
            triggers.forEach((trigger) => {
                trigger.classList.toggle('active', trigger.dataset.commercialDetailTabTrigger === targetTab);
            });

            detailPanels.forEach((detailPanel) => {
                detailPanel.classList.toggle('is-hidden', detailPanel.dataset.commercialDetailTabPanel !== targetTab);
            });
        };

        triggers.forEach((trigger) => {
            trigger.addEventListener('click', () => {
                activate(trigger.dataset.commercialDetailTabTrigger);
            });
        });

        activate(triggers[0].dataset.commercialDetailTabTrigger);
    });
}

function bindSortableTables() {
    document.querySelectorAll('table[data-sortable-table]').forEach((table) => {
        makeTableSortable(table);
    });
}

function makeTableSortable(table) {
    const tbody = table.querySelector('tbody[data-sort-body]');
    const headers = [...table.querySelectorAll('thead th[data-sortable="true"]')];

    if (!tbody || !headers.length) {
        return;
    }

    headers.forEach((header) => {
        const columnIndex = header.cellIndex;

        header.addEventListener('click', () => {
            const stateKey = tbody.dataset.sortBody;
            const current = tableSortState.get(stateKey);
            const direction = current?.columnIndex === columnIndex && current.direction === 'asc' ? 'desc' : 'asc';
            const state = { columnIndex, direction };

            tableSortState.set(stateKey, state);
            sortRowsByColumn(tbody, columnIndex, direction);
            updateSortIndicators(table, state);
        });
    });
}

function sortRowsByColumn(tbody, columnIndex, direction) {
    const rows = [...tbody.querySelectorAll('tr')];
    const sortableRows = rows.filter((row) => row.children.length > 1);
    const fillerRows = rows.filter((row) => row.children.length <= 1);
    const multiplier = direction === 'asc' ? 1 : -1;

    sortableRows.sort((a, b) => {
        const aCell = a.children[columnIndex];
        const bCell = b.children[columnIndex];
        const aValue = parseSortableValue(aCell?.dataset.sortValue || aCell?.textContent);
        const bValue = parseSortableValue(bCell?.dataset.sortValue || bCell?.textContent);

        if (aValue.empty && bValue.empty) {
            return 0;
        }

        if (aValue.empty) {
            return 1;
        }

        if (bValue.empty) {
            return -1;
        }

        if (aValue.type === 'number' && bValue.type === 'number') {
            return (aValue.value - bValue.value) * multiplier;
        }

        return aValue.value.localeCompare(bValue.value, 'es', { sensitivity: 'base' }) * multiplier;
    });

    [...sortableRows, ...fillerRows].forEach((row) => tbody.appendChild(row));
}

function parseSortableValue(value) {
    const raw = String(value || '').trim();

    if (raw === '' || raw === '-') {
        return { empty: true, type: 'text', value: '' };
    }

    const primary = raw.split('(')[0].trim();
    const normalized = primary
        .replaceAll('EUR', '')
        .replaceAll('eur', '')
        .replaceAll('€', '')
        .replaceAll('%', '')
        .replace(/\s+/g, '')
        .replace(/^\+/, '');
    const numericCandidate = normalized.includes(',')
        ? normalized.replaceAll('.', '').replace(',', '.')
        : (/^-?\d{1,3}(\.\d{3})+(\.\d+)?$/.test(normalized) ? normalized.replaceAll('.', '') : normalized);
    const number = Number(numericCandidate);

    if (!Number.isNaN(number) && /^-?\d+(\.\d+)?$/.test(numericCandidate)) {
        return { empty: false, type: 'number', value: number };
    }

    return { empty: false, type: 'text', value: raw.toLocaleLowerCase('es') };
}

function updateSortIndicators(table, state) {
    table.querySelectorAll('thead th[data-sortable="true"]').forEach((header) => {
        header.querySelector('.sort-indicator')?.remove();

        if (header.cellIndex === state.columnIndex) {
            header.insertAdjacentHTML('beforeend', ` <span class="sort-indicator">${state.direction === 'asc' ? '▲' : '▼'}</span>`);
        }
    });
}

function initCommissionSummaryColumns() {
    const table = document.getElementById('commissionSummaryTable');
    const button = document.getElementById('commissionSummaryColumnsButton');
    const popover = document.getElementById('commissionSummaryColumnsPopover');

    if (!table || !button || !popover) {
        return;
    }

    renderCommissionSummaryColumnsPopover();
    applyCommissionSummaryColumnVisibility();

    button.addEventListener('click', () => {
        popover.classList.toggle('is-hidden');
    });

    popover.addEventListener('change', (event) => {
        const input = event.target.closest('[data-column-toggle]');

        if (!input) {
            return;
        }

        const visible = new Set(commissionSummaryVisibleColumns);
        const key = input.dataset.columnToggle;

        if (input.checked) {
            visible.add(key);
        } else {
            visible.delete(key);
        }

        commissionSummaryVisibleColumns = commissionSummaryColumnDefinitions
            .filter((column) => column.alwaysVisible || visible.has(column.key))
            .map((column) => column.key);

        localStorage.setItem(
            commissionSummaryColumnsStorageKey,
            JSON.stringify(commissionSummaryVisibleColumns)
        );

        applyCommissionSummaryColumnVisibility();
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.columns-menu')) {
            popover.classList.add('is-hidden');
        }
    });
}

function initCommissionSummaryHorizontalScroll() {
    const topScroll = document.getElementById('commissionSummaryTableScrollTop');
    const spacer = document.getElementById('commissionSummaryTableScrollTopSpacer');
    const shell = document.getElementById('commissionSummaryTableShell');
    const table = document.getElementById('commissionSummaryTable');

    if (!topScroll || !spacer || !shell || !table) {
        return;
    }

    let syncingFromTop = false;
    let syncingFromShell = false;

    syncCommissionSummaryScrollbar = () => {
        spacer.style.width = `${table.scrollWidth}px`;
        topScroll.scrollLeft = shell.scrollLeft;
    };

    topScroll.addEventListener('scroll', () => {
        if (syncingFromShell) {
            return;
        }

        syncingFromTop = true;
        shell.scrollLeft = topScroll.scrollLeft;
        syncingFromTop = false;
    });

    shell.addEventListener('scroll', () => {
        if (syncingFromTop) {
            return;
        }

        syncingFromShell = true;
        topScroll.scrollLeft = shell.scrollLeft;
        syncingFromShell = false;
    });

    if (typeof ResizeObserver === 'function') {
        const resizeObserver = new ResizeObserver(() => syncCommissionSummaryScrollbar());
        resizeObserver.observe(table);
        resizeObserver.observe(shell);
    } else {
        window.addEventListener('resize', syncCommissionSummaryScrollbar);
    }

    syncCommissionSummaryScrollbar();
}

function renderCommissionSummaryColumnsPopover() {
    const popover = document.getElementById('commissionSummaryColumnsPopover');

    if (!popover) {
        return;
    }

    popover.innerHTML = commissionSummaryColumnDefinitions
        .filter((column) => !column.alwaysVisible)
        .map((column) => `
            <label class="column-option switch-option">
                <input type="checkbox" data-column-toggle="${escapeHtml(column.key)}" ${commissionSummaryVisibleColumns.includes(column.key) ? 'checked' : ''}>
                <span>${escapeHtml(column.label)}</span>
            </label>
        `)
        .join('');
}

function applyCommissionSummaryColumnVisibility() {
    document.querySelectorAll('#commissionSummaryTable [data-column]').forEach((cell) => {
        cell.classList.toggle('is-hidden', !commissionSummaryVisibleColumns.includes(cell.dataset.column));
    });

    syncCommissionSummaryScrollbar();
}

function loadVisibleColumns(storageKey, definitions) {
    const defaults = definitions
        .filter((column) => column.alwaysVisible)
        .map((column) => column.key);

    try {
        const stored = JSON.parse(localStorage.getItem(storageKey) || '[]');

        if (!Array.isArray(stored)) {
            return defaults;
        }

        return definitions
            .filter((column) => column.alwaysVisible || stored.includes(column.key))
            .map((column) => column.key);
    } catch (error) {
        return defaults;
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function bindKpiTooltips() {
    const tooltips = {
        'Mes analizado': 'Mes cerrado usado por el informe. Pivota por cv_signed_date, que viene de Fecha_firma_contrato__c.',
        Oportunidades: 'Conteo de salesforce_opportunities con cv_signed=true, stage_name distinto de Cerrada perdida, record_type Venta/Cambio/Tasacion y gestion_de_venta false o null.',
        Resenas: 'Conteo de salesforce_reviews creadas dentro del mes. Fuente Salesforce: objeto Resena__c.',
        Estado: 'Indica si existen bloqueos de datos o estructura que impiden calcular el informe.',
        Comerciales: 'Numero de comerciales o tasadores con actividad real en el resumen: venta/cambio del mes o compra liquidada por venta posterior del vehiculo.',
        'Comision final': 'Formula: max(prima ajustada - penalizaciones, 0) + producto financiacion + producto garantias.',
        'Prima ajustada': 'Formula: prima total x tramo de entregas.',
        Penalizaciones: 'Suma de penalizacion por garantias, penalizacion por resenas y penalizacion por financiacion.',
        Entregas: 'Conteo de oportunidades de tipo Venta y Cambio del mes. Cada entrega suma 60 EUR.',
        Operaciones: 'Conteo de oportunidades de tipo Venta, Cambio y Tasacion del mes.',
        'Compras liquidadas': 'Compras historicas enlazadas a una venta del mes. Se atribuyen al Comprador_oportunidad__c del Product2 y la formula es: precio_venta - precio_compra - descuento + beneficio_financiacion + garantia. Sobre ese resultado se aplica el 1.8%.',
        Compartidas: 'Suma de 30 EUR por cada oportunidad con Entrega_Compartida__c.',
        Ventas: 'Importe calculado como entregas x 60 EUR.',
        'Stock +150': 'Suma de 10 EUR por cada entrega con Dias_en_stock__c >= 150.',
        'Bonus +15': 'Suma de 30 EUR por cada entrega a partir de la numero 16.',
        'Prima total': 'Formula: ventas + compras + compartidas - descuento 5% + stock +150 + bonus +15.',
        'Prima neta': 'Formula: max(prima ajustada - penalizaciones, 0).',
        'Prod. financiacion': 'Producto calculado por tramos sobre Beneficio_financiacion_comercial__c.',
        'Prod. garantias': 'Producto calculado por tramos sobre Garant_a_Total__c.',
    };

    document.querySelectorAll('.commercial-commissions-report .campaign-kpi .kpi-label, .commercial-commissions-report .campaign-context-card > span, .commercial-commissions-report .platform-metric-item > span')
        .forEach((element) => {
            const label = element.textContent.trim();
            const tooltip = tooltips[label];

            if (!tooltip) {
                return;
            }

            element.classList.add('kpi-tooltip');
            element.dataset.kpiTooltip = tooltip;
            element.removeAttribute('title');
        });
}
