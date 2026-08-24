# Contexto técnico del proyecto

Actualizado: 2026-08-21.

## SEO/Analytics

- `App\Services\SeoAnalytics` separa clientes HTTP, sincronización/persistencia
  y dataset de render. `GET /informes/seo-analytics` solo lee BD local y config.
- Search Console conserva agregados diarios exactos finales separados de
  rankings top 7/28/90 reemplazables. Salesforce SEO usa una proyección propia
  de `Medio_origen__c = 'Orgánico'`, sin alterar `salesforce_leads.medio_origen`.
- GA4 persiste `keyEvents` Organic Search/web como decimal, con totales ALL/ESP
  separados del detalle España por evento. Usa timezone de property, lag
  operativo y rolling refresh; nunca se suma con Leads Salesforce. Cada página
  Data API supera una quality gate de thresholding, data loss y sampling antes
  de que una ausencia pueda convertirse en cero. Sus strings `TYPE_FLOAT` se
  normalizan a escala 6 mediante aritmética decimal textual, sin redondeo ni
  conversión IEEE-754.
- Las fuentes se sincronizan por comandos independientes y scheduler monitorizado;
  cada cutoff visible procede del último `ReportSyncRun` completado. La
  disponibilidad de KPI exige además cobertura diaria local completa.
  Resumen/Tráfico usan el cutoff común mínimo; rankings usan el periodo propio
  de Search Console y su property configurada.
- Salud técnica está implementada como monitor acotado y persistido. El motor
  comparativo transversal persiste snapshots diarios SEO con D-7/D-14/D-21/
  D-28, mínimo 3/4 y D-364 opcional usando el cutoff propio de cada fuente. El
  Lote 6 mantiene esos hechos intactos y añade evaluaciones versionadas locales,
  configurables por Administrador/Director, sin scoring IA. SISTRIX AI permanece
  fuera. Contrato: `docs/ai/SEO_ANALYTICS.md`.

### Snapshots analíticos transversales

- `App\Services\Analytics\SameWeekdayComparisonEngine` es un core sin queries,
  modelos ni conceptos SEO. `same_weekday_v1` conserva ausencia distinta de
  cero y deja sin porcentaje las referencias cero.
- `analytical_metric_snapshots` es una proyección transversal e idempotente con
  rolling upsert, no un histórico append-only. SEO es el primer adaptador con
  seis métricas y properties aisladas mediante una identidad técnica y su hash
  SHA-256.
- `seo:build-analytical-snapshots --days=30` carga una serie por fuente local,
  hace rolling rebuild sin borrar historia y se ejecuta a las 06:15 Madrid.
  Estos snapshots quedan fuera de pruning hasta aprobar una política propia.
  El builder admite 1–90 días y su default operativo consume la configuración
  interna de 30; los comandos de ingesta mantienen su contrato separado de
  1–480 días y scheduler de 120.

### Evaluaciones analíticas SEO

- `AnalyticalEvaluationEngine` es un core transversal sin Eloquent, Request ni
  conceptos SEO. Recibe snapshot y regla resueltos y devuelve estado, dirección,
  banda y reason code cerrados.
- `analytical_rule_sets` y `analytical_metric_rules` conservan versiones
  inmutables; `analytical_metric_evaluations` mantiene auditoría por snapshot y
  versión. `seo_rules_v1` contiene exactamente seis reglas.
- Cada evaluación captura sus cuatro magnitudes factuales, evaluabilidad, motivo
  y fingerprint SHA-256. Una revisión rolling invalida temporalmente la unión
  visible hasta reevaluar; las señales históricas leen la captura, no el
  snapshot mutable. Recalcular solo timestamps o D-364 no invalida v1.
- La configuración vive en BD, no `.env`. Solo Administrador/Director pueden
  crear la siguiente versión; cada cambio exige motivo y reevalúa el estado
  actual sin reescribir el histórico.
- `seo:evaluate-analytical-snapshots` es local, idempotente y se ejecuta a las
  06:30 Madrid. El panel de señales limita la lectura a 30 días/50 filas y usa
  las properties actualmente configuradas.

### Correo ejecutivo SEO

- `SeoExecutiveDailyReportDatasetService` compone solo las seis comparativas,
  la frescura compartida de Search Console/Salesforce/GA4 y Salud técnica
  factual. No construye el dashboard descriptivo ni llama proveedores.
- Los destinatarios (1–10) viven en `seo_executive_email_settings`; solo
  Administrador/Director los gestionan. SMTP y remitente permanecen en `MAIL_*`.
- `seo_executive_daily_reports` congela un payload por fecha y
  `seo_executive_email_deliveries` aporta ledger idempotente individual. Un
  retry no reconstruye el contenido ni reenvía estados `sent`/`sending`. El
  retorno correcto del SMTP cierra la fase reintentable: si la confirmación
  local de `sent` queda incierta, el ledger conserva `sending` y requiere
  reconciliación manual.
- `seo:send-executive-daily-email` usa Laravel Mail síncrono, registra
  `ReportSyncRun` y se ejecuta a las 08:00 Madrid con lock de 30 minutos. Solo el
  fallo técnico del scheduler participa en `OperationalAlert`.

### Salud técnica SEO

- Un comando programado monitoriza únicamente Home, configuración estratégica
  y páginas del ranking local Search Console; no recorre enlaces ni infiere URLs
  de Stock.
- Robots y sitemap describen infraestructura y membership del conjunto
  seleccionado. Los checks HTTP diarios persisten hechos técnicos, no severidad
  analítica.
- Todo fetch usa allowlist exacta, host DNS ASCII canónico, todas las IP
  globales, proxy desactivado, pin `CURLOPT_RESOLVE`, TLS verificado y redirects
  manuales. La lectura streaming es acotada y un body parcial nunca acredita
  conclusiones negativas de noindex/canonical. El dashboard sigue leyendo
  exclusivamente BD/config y queda fuera del common cutoff.

## Design System de informes

- `resources/css/reports/design-system.css` define tokens `--report-ui-*` y
  primitives `report-ui-*` aislados del CSS legacy. Se carga antes del CSS del
  Application Shell y no contiene selectores globales de elementos o clases
  genéricas.
- Los componentes Blade visuales viven en `resources/views/components/reports/ui`.
  Son presentacionales, conservan el escape de Blade y no consultan datos.
- Los patrones analíticos compartidos viven en el mismo bundle: KPI strip, data
  panel, section header, tabla densa, tabs lineales, filter bar, highlight neutral
  y source status. No incluyen datos, comportamiento JavaScript ni taxonomías
  funcionales propias.
- Resumen y SEO/Analytics son las primeras pantallas migradas. Los seis
  dashboards conservan sus estilos internos hasta lotes específicos.
- Los estados analíticos oficiales son `ok`, `observation`, `deviation`,
  `critical` y `not-evaluable`; cualquier clave desconocida usa el último como
  fallback seguro. El contrato operativo está en `docs/ai/DESIGN_SYSTEM.md`.

## Application shell de informes

- Las paginas autenticadas de informes y administracion usan el componente
  Blade anonimo `x-reports.app-shell`. El componente centraliza `head`,
  branding, topbar, usuario, logout, sidebar y contenedor de contenido; cada
  pagina aporta titulo, modulo activo, clases de `body`, assets y contenido.
- `resources/css/reports/app-shell.css` consume los tokens compartidos y mantiene
  la estructura responsive. No existe modo oscuro todavia. El contenido analitico
  no recibe un `max-width` global; los limites de lectura deben seguir siendo
  especificos de cada pagina cuando sean necesarios.
- `resources/js/reports/app-shell.js` gestiona exclusivamente la sidebar. En
  escritorio persiste el estado abierto/cerrado en `localStorage`; en movil es
  un drawer superpuesto. Los fallos de almacenamiento se ignoran de forma
  segura y no existe estado de navegacion en servidor.
- La navegacion se resuelve en servidor mediante `ReportUserAccess` y se
  materializa una sola vez por request. Ocultar un enlace no sustituye al
  middleware o control de autorizacion de la ruta.

## Operación transversal

- GitHub Actions es la CI canónica: PHP 8.4, Composer bloqueado, audit runtime
  `composer audit --locked --no-dev`, SQLite de testing, suite, Pint, Vite y
  `git diff --check` con permisos de solo lectura.
- Producción no dispone de Node/npm; despliega `public/build` ya construido.
- Las APIs internas entrantes usan credenciales de entorno identificables por
  integración/versión, rate limit por integración y audit log diario sin body.
- `OperationalAlert` centraliza alertas técnicas deduplicadas visibles solo a
  administradores. No se usan email, Slack, SMS ni Salesforce como canales.
- `reports:prune-transversal-data` es la única entrada de retención de datos:
  chunks, dry-run e índices dedicados. Solo anula los ocho payloads sin lecturas
  funcionales. Los cinco payloads aún consumidos quedan bloqueados y documentados.
- `/up` es liveness, no readiness de dependencias.

## Convenciones de exportación auditada

- Los CSV con valores compuestos deben usar `App\Support\CsvValueSerializer`;
  no deben pasar arrays u objetos directamente a `fputcsv`.
- Las exportaciones voluminosas deben escribir directamente al stream mediante
  cursor o lotes, con ámbitos resueltos en servidor antes de producir filas.
- KPI, JSON de auditoría y CSV deben consumir la misma resolución de cohorte.
- Los CSV estándar de auditoría no deben seleccionar datos personales que no
  sean imprescindibles para explicar la métrica.
