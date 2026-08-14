<x-reports.app-shell title="Resumen" current-report="summary">
    <div class="wrap">
        <main>
            <x-reports.ui.page-header
                eyebrow="Visión estratégica"
                title="Resumen"
                description="Este espacio alojará la visión transversal de los informes cuando se implemente la siguiente fase analítica."
            />

            <x-reports.ui.empty-state
                kicker="Módulo estructural"
                title="Sin datos analíticos en este lote"
                title-id="summary-status-title"
                description="La página no ejecuta consultas de dashboards ni llamadas a servicios externos."
                aria-labelledby="summary-status-title"
            />
        </main>
    </div>
</x-reports.app-shell>
