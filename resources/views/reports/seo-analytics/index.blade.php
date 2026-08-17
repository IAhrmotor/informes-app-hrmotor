<x-reports.app-shell title="SEO y Analytics" current-report="seo-analytics">
    <div class="wrap">
        <main>
            <x-reports.ui.page-header
                eyebrow="Marketing estratégico"
                title="SEO y Analytics"
                description="Fundamento técnico para las futuras métricas de visibilidad orgánica y analítica web."
            />

            <section class="report-ui-data-panel" aria-label="Integraciones previstas">
                <div class="report-ui-data-panel__header">
                    <x-reports.ui.section-header
                        title="Integraciones previstas"
                        description="La configuración local no acredita todavía acceso a los proveedores. La verificación live es exclusivamente manual mediante CLI."
                    />
                </div>
                <div class="report-ui-data-panel__body">
                    @foreach ($sources as $source)
                        <x-reports.ui.source-status
                            :title="$source['title']"
                            :detail="$source['detail']"
                            :data-source="$source['key']"
                        >
                            <x-slot:indicator>
                                <span class="report-ui-badge">{{ $source['badge'] }}</span>
                            </x-slot:indicator>
                        </x-reports.ui.source-status>
                    @endforeach
                </div>
            </section>

            <x-reports.ui.empty-state
                kicker="Pendiente de conexión"
                title="Sin métricas analíticas en este lote"
                title-id="seo-status-title"
                description="Esta pantalla no muestra métricas ficticias ni realiza llamadas a Google, Salesforce o SISTRIX."
                aria-labelledby="seo-status-title"
            />
        </main>
    </div>
</x-reports.app-shell>
