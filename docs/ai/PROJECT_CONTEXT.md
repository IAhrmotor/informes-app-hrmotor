# Contexto técnico del proyecto

Actualizado: 2026-08-17.

## SEO/Analytics

- El fundamento de integraciones vive en `App\Services\SeoAnalytics`: OAuth
  compartido solo en implementación, credenciales separadas para Search Console
  y GA4, cliente SISTRIX básico y resolver puro de metadata Lead Salesforce.
- `GET /informes/seo-analytics` muestra readiness neutral leyendo únicamente
  `config()`. La red externa queda limitada al comando manual
  `seo:diagnose-integrations --live`; el modo por defecto es config-only.
- No existen todavía métricas, tablas, snapshots, scheduler o alertas SEO. El
  contrato operativo y de persistencia futura está en `docs/ai/SEO_ANALYTICS.md`.

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

- GitHub Actions es la CI canónica: PHP 8.4, Composer bloqueado, SQLite de
  testing, suite, Pint, Vite y `git diff --check` con permisos de solo lectura.
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
