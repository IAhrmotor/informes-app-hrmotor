<x-reports.app-shell title="Configuración analítica SEO" current-report="seo-analytics">
    <div class="wrap">
        <main>
            <x-reports.ui.page-header
                eyebrow="SEO y Analytics"
                title="Reglas de evaluación"
                description="Los cambios crean una versión nueva y reevaluan únicamente la situación actual. Las versiones anteriores permanecen para auditoría."
            >
                <x-slot:actions>
                    <a class="report-ui-button report-ui-button--secondary" href="{{ route('reports.seo-analytics.index', ['section' => 'summary']) }}">Volver al informe</a>
                </x-slot:actions>
            </x-reports.ui.page-header>

            @if (session('status'))
                <div class="report-ui-card report-ui-card--muted" role="status" style="margin-bottom: var(--report-ui-space-4)">
                    {{ session('status') }}
                </div>
            @endif
            @if (session('email_status'))
                <div class="report-ui-card report-ui-card--muted" role="status" style="margin-bottom: var(--report-ui-space-4)">
                    {{ session('email_status') }}
                </div>
            @endif
            @if ($errors->any())
                <div class="report-ui-card" role="alert" style="margin-bottom: var(--report-ui-space-4)">
                    <strong>No se han guardado los cambios.</strong>
                    <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <section class="report-ui-data-panel" aria-label="Versión analítica activa">
                <div class="report-ui-data-panel__header">
                    <x-reports.ui.section-header
                        title="Versión activa: {{ $active->version_key }}"
                        description="Activada el {{ $active->activated_at->format('Y-m-d H:i') }}. Modo, dirección favorable y unidad son contratos no editables."
                    />
                </div>
                <form method="POST" action="{{ route('reports.seo-analytics.settings.update') }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="base_rule_set_id" value="{{ old('base_rule_set_id', $active->id) }}">
                    <input type="hidden" name="base_version_number" value="{{ old('base_version_number', $active->version_number) }}">

                    <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Umbrales analíticos SEO editables">
                        <table class="report-ui-table">
                            <thead>
                                <tr>
                                    <th scope="col">Métrica y contrato</th>
                                    <th scope="col">Observación</th>
                                    <th scope="col">Desviación</th>
                                    <th scope="col">Crítico</th>
                                    <th scope="col">Baseline mínimo</th>
                                    <th scope="col">Cambio absoluto mínimo</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($definitions as $definition)
                                    @php($rule = $definition['rule'])
                                    <tr>
                                        <th scope="row">
                                            {{ $definition['label'] }}
                                            <div class="report-ui-help">
                                                {{ $rule->comparison_mode }} · favorable: {{ $rule->favorable_direction }} · unidad: {{ $rule->threshold_unit }}
                                            </div>
                                        </th>
                                        @foreach (['observation_threshold', 'deviation_threshold', 'critical_threshold'] as $field)
                                            <td>
                                                <label class="report-ui-field">
                                                    <span class="report-ui-label">{{ match($field) { 'observation_threshold' => 'Observación', 'deviation_threshold' => 'Desviación', default => 'Crítico' } }} [{{ $rule->threshold_unit === 'percent' ? '%' : ($rule->threshold_unit === 'percentage_points' ? 'pp' : 'posiciones') }}]</span>
                                                    <input class="report-ui-input" inputmode="decimal" name="rules[{{ $rule->metric_key }}][{{ $field }}]" value="{{ old('rules.'.$rule->metric_key.'.'.$field, $rule->{$field}) }}" required>
                                                </label>
                                            </td>
                                        @endforeach
                                        @if ($rule->comparison_mode === 'relative_percent')
                                            <td><label class="report-ui-field"><span class="report-ui-label">Baseline mínimo</span><input class="report-ui-input" inputmode="decimal" name="rules[{{ $rule->metric_key }}][minimum_baseline]" value="{{ old('rules.'.$rule->metric_key.'.minimum_baseline', $rule->minimum_baseline) }}" required></label></td>
                                            <td><label class="report-ui-field"><span class="report-ui-label">Cambio absoluto mínimo</span><input class="report-ui-input" inputmode="decimal" name="rules[{{ $rule->metric_key }}][minimum_absolute_change]" value="{{ old('rules.'.$rule->metric_key.'.minimum_absolute_change', $rule->minimum_absolute_change) }}" required></label></td>
                                        @else
                                            <td colspan="2"><span class="report-ui-help">No aplica: sus thresholds absolutos ya representan la materialidad.</span></td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="report-ui-data-panel__body">
                        <label class="report-ui-field">
                            <span class="report-ui-label">Motivo del cambio</span>
                            <textarea class="report-ui-textarea" name="change_reason" maxlength="500" required>{{ old('change_reason') }}</textarea>
                            <span class="report-ui-help">Obligatorio, entre 1 y 500 caracteres. No incluyas credenciales ni datos personales.</span>
                        </label>
                        <button class="report-ui-button" type="submit">Crear nueva versión y aplicar</button>
                    </div>
                </form>
            </section>

            <section class="report-ui-data-panel" style="margin-top: var(--report-ui-space-4)" aria-label="Correo ejecutivo diario">
                <div class="report-ui-data-panel__header">
                    <x-reports.ui.section-header
                        title="Correo ejecutivo diario"
                        description="Se envía todos los días a las 08:00 Europe/Madrid. Una dirección por línea, con un máximo de 10."
                    />
                </div>
                <form method="POST" action="{{ route('reports.seo-analytics.settings.email.update') }}">
                    @csrf
                    @method('PUT')
                    <div class="report-ui-data-panel__body">
                        <p><span class="report-ui-badge">{{ $mailReadiness['label'] }}</span></p>
                        <label class="report-ui-field">
                            <span class="report-ui-label">Destinatarios</span>
                            <textarea class="report-ui-textarea" name="email_recipients" rows="6" maxlength="5000" required>{{ old('email_recipients', $emailRecipients) }}</textarea>
                            <span class="report-ui-help">Solo Administrador y Director pueden modificar esta lista. Las credenciales del transporte permanecen en la configuración segura del servidor.</span>
                        </label>
                        <button class="report-ui-button" type="submit">Guardar destinatarios</button>
                    </div>
                </form>
            </section>

            <section class="report-ui-data-panel" style="margin-top: var(--report-ui-space-4)" aria-label="Histórico de versiones analíticas">
                <div class="report-ui-data-panel__header"><x-reports.ui.section-header title="Histórico reciente de versiones" /></div>
                <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Últimas versiones de reglas SEO">
                    <table class="report-ui-table">
                        <thead><tr><th scope="col">Versión</th><th scope="col">Activación</th><th scope="col">Actor interno</th><th scope="col">Motivo</th><th scope="col">Estado</th></tr></thead>
                        <tbody>
                            @foreach ($history as $version)
                                <tr><td>{{ $version->version_key }}</td><td>{{ $version->activated_at->format('Y-m-d H:i') }}</td><td>{{ $version->created_by_report_user_id ?? 'Sistema' }}</td><td>{{ $version->change_reason ?? 'Configuración inicial aprobada' }}</td><td><span class="report-ui-badge">{{ $version->status === 'active' ? 'Activa' : 'Sustituida' }}</span></td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</x-reports.app-shell>
