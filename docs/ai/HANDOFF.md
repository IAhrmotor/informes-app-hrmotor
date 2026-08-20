# Handoff para agentes

## API mensual canónica de Comisiones Comerciales (2026-08-20)

- `GET /api/comisiones_comercial` mantiene `salesforce_id` obligatorio y añade
  `month=YYYY-MM` opcional. El mes explícito se valida estrictamente: formato,
  mes real y no futuro; los errores son `422` sin fallback silencioso. Sin mes se
  usa `CommissionMonthResolver`.
- La fuente y la fila se resuelven antes que la elegibilidad actual. Una fila de
  snapshot definitivo se devuelve aunque el usuario haya quedado inactivo,
  cambiado a perfil no comercial o desaparecido de `salesforce_users`. Reopened
  continúa ignorando el snapshot anterior y usa el cálculo vivo.
- Si no existe fila canónica, la elegibilidad fallback exige coincidencia PHP
  exacta —también en mayúsculas/minúsculas—, `is_active=true` y las reglas ya
  usadas por `CommercialCommissionDashboardService`. No hay búsqueda por
  nombre/email, coincidencia parcial ni consulta Salesforce en vivo.
- La respuesta mensual expone metadatos no económicos, `has_data` y la `row`
  exacta de `summary_rows`, incluidos `details`. `200`/`row=null` significa solo
  usuario activo/elegible sin fila; IDs inexistentes, técnicos, inactivos o no
  elegibles reciben un `404` genérico si no existe fila congelada. Nunca se
  devuelven las demás filas del dataset.
- Los definitivos leen `definitiveSnapshot(month, commercials)` antes de
  construir el cálculo vivo. Provisional, pendiente y reabierto ejecutan
  `build(month, true, false, true)` una sola vez: sin Delegaciones ni las otras
  pestañas. No se copiaron ni modificaron fórmulas económicas.
- Un payload vivo con `ready !== true` devuelve `503` genérico, sin `issues`,
  filas ni importes. La compatibilidad sin `month` también falla completa si el
  mes actual o el mes anterior requerido no están disponibles; no fabrica cero.
- Las peticiones sin `month` conservan temporalmente `current_month` y
  `previous_closed_month`; además reciben el contrato mensual canónico. Las
  peticiones con `month` no calculan el mes anterior.
- Archivos modificados: controlador API, servicio de dashboard (consulta pública
  de elegibilidad exacta), test feature de API, documentación funcional,
  operativa, general, README y este handoff. Sin migraciones, dependencias,
  variables de entorno ni cambios en middleware, cierres, snapshots o SEO.
- Seguridad: se conservan `internal.api.audit`, `commissions.api.auth` e
  `internal.api.throttle`, `X-Request-ID` y el logging sin query/body/details ni
  secretos. Las pruebas usan exclusivamente credenciales y datos sintéticos.
- Rendimiento: un mes explícito realiza una sola construcción económica y una
  sola carga de sus `summary_rows`; un definitivo no ejecuta cálculo vivo. La
  compatibilidad sin mes mantiene dos meses por contrato legacy.
- Validación final de esta corrección: sintaxis PHP correcta; API 22 pruebas /
  138 aserciones (`8187 ms`); dashboard 45 / 294 (`12199 ms`); cierres 9 / 72
  (`5689 ms`); suite completa 586 / 4.206 (`261287 ms`), todo correcto.
  `vendor/bin/pint --dirty --test`, `git diff --check` y la ruta con
  `internal.api.audit`, `commissions.api.auth` e `internal.api.throttle`
  también correctos.
- Acciones manuales: ninguna migración ni configuración nueva. Antes de retirar
  los campos legacy debe identificarse y migrarse el consumidor externo.
- Riesgo residual: el consumidor externo no está localizado en el repositorio;
  por ello no se eliminó el contrato anterior. No se desplegó, sincronizó ni
  modificó información real.

## SEO/Analytics Lote 5 - Comparativa diaria (2026-08-19)

- Se añadió el core transversal `SameWeekdayComparisonEngine`, sin queries ni
  conceptos SEO. `same_weekday_v1` usa D-7/D-14/D-21/D-28 exactos, mínimo 3/4,
  D-364 opcional, ausencia distinta de cero y variación relativa nula cuando el
  baseline es cero. No existen thresholds, scoring ni severidad.
- La migración aditiva `2026_08_19_090000` crea
  `analytical_metric_snapshots` con DECIMAL, unique por módulo/métrica/scope/
  hash de fuente/fecha e índices de lectura. No hay pruning aprobado.
- `SeoAnalyticalSnapshotService` carga una serie local por fuente, construye
  exactamente seis KPI y hace upsert transaccional por chunks. Search Console y
  GA4 se aíslan por property; Salesforce usa `salesforce-organic-leads` y scope
  `all`. Cada fuente conserva su cutoff propio.
- `seo:build-analytical-snapshots --days=30` admite 1–90, registra
  `ReportSyncRun`, no hace HTTP y se agenda a las 06:15 Madrid con lock 120. Un
  fallo interno se sanitiza; una fuente ausente no bloquea las disponibles.
- Resumen lee solo el último snapshot compatible mediante un dataset separado.
  La tabla factual es independiente de range 7/28/90, accesible, sin colores ni
  estados de severidad. Salud técnica queda fuera.
- Corrección de revisión: actual y D-364 conservan formato entero para métricas
  de conteo, pero baseline y diferencia muestran dos decimales (`9,50` y
  `+0,50`). Las constantes de `SameWeekdayComparisonEngine` son la única fuente
  del contrato y `config/seo_analytics.php` las referencia. El default del
  builder consume `snapshot_refresh_days=30` y el scheduler invoca ese default
  sin duplicarlo; ingestas admiten 1–480 y builder 1–90.
- Corrección CI: `SeoAnalyticalSnapshotsTest` configura credenciales sintéticas
  y properties locales para que los guards `configured()` no dependan del
  entorno, y bloquea cualquier request HTTP no simulada. Los casos que validan
  ausencia de fuentes limpian explícitamente toda esa configuración. No se
  añadieron secretos ni se modificó el builder productivo o el workflow.
- Seguridad/rendimiento: cero credenciales/PII, cero endpoints de escritura,
  cero requests, una query de serie por fuente, sin N+1 y transacción corta solo
  para persistencia. No se tocaron ingestas Search Console/Salesforce/GA4,
  normalizador científico, Salud técnica, CSS ni JavaScript.
- Operación pendiente: migrar; opcionalmente sincronizar 400 días para D-364;
  construir 30 días; verificar scheduler, run local y conteos. Producción no
  requiere Node y no debe borrar snapshots.
- Validación final: motor 7 pruebas/42 aserciones, SEO analítico 10/82, suite SEO
  95/771 y suite completa 569/4.081, correctos. Pint `--dirty --test`, Composer
  audit runtime (cero advisories), Vite 8.0.12 y `git diff --check`, correctos.
  El build regeneró únicamente el bundle CSS general: retiró el hash anterior,
  creó el nuevo y actualizó `public/build/manifest.json`; no cambió CSS o
  JavaScript fuente en esta corrección.

## Dependencias runtime sin advisories (2026-08-19)

- Actualización focalizada dentro de majors: Laravel 13.8.0 -> 13.12.0,
  CommonMark 2.8.2 -> 2.9.0, cinco componentes Symfony afectados -> 8.0.15 e
  `symfony/polyfill-intl-idn` 1.37.0 -> 1.38.1. No cambió ningún otro paquete
  del lock final.
- Guzzle permanece 7.15.3 y PSR-7 2.13.0. `composer.json` bloquea tanto sus
  rangos vulnerables como Guzzle 8/PSR-7 3 para conservar los majors aprobados.
- GitHub Actions ejecuta `composer audit --locked --no-dev` después de instalar
  dependencias, sin ignores ni tolerancia de fallo. Operación instala el lock y
  repite el audit; nunca ejecuta update en producción.
- Seguridad: `composer audit --locked --no-dev` y el audit completo devuelven
  cero advisories. No se añadieron dependencias, supresiones, secretos ni
  permisos de CI.
- Rendimiento/funcionalidad: no cambian aplicación, consultas, scheduler,
  sincronizadores, frontend, assets ni esquemas. Suite SEO 82/628,
  autenticación 8/43, navegación estratégica 6/84 y suite completa 545/3.836
  correctas (315,45 s). Pint, Vite 8.0.12 y `git diff --check`, correctos.
- Archivos: `composer.json`, `composer.lock`, `.github/workflows/ci.yml` y
  documentación operativa/AI. No hay migraciones ni configuración manual nueva;
  producción deberá desplegar el lock aprobado mediante
  `composer install --no-dev` y exigir audit runtime limpio.

## SEO/Analytics Lote 4 - Salud técnica acotada (hardening 2026-08-19)

- Selección cerrada: Home, URLs estratégicas configuradas y hasta 150 páginas
  del ranking local Search Console ESP/90; default 200 URLs y hard cap 500. No
  existe crawler, navegación de enlaces ni fuente Stock directa.
- El checker obtiene robots/sitemaps y realiza un GET por candidato más redirects
  válidos. Guard SSRF único: HTTP/S y puertos estándar, hosts exactos, DNS
  íntegramente público, IP fijada al transporte y redirects manuales. Bodies,
  cookies, Authorization y headers completos no se persisten.
- Dos migraciones aditivas crean el registro activo/inactivo y un check único por
  URL/día. Sitemap membership diferencia `true`, `false` y `null`; la red se
  completa antes de la transacción corta de upsert.
- `seo:sync-technical-health` publica `ReportSyncRun` con host y contadores no
  sensibles. Scheduler: 06:00 Madrid, lock 120 y monitor técnico. Los findings
  URL no generan alertas operativas ni estados analíticos.
- Salud técnica renderiza solo BD/config, fuera del common cutoff y sin selector
  7/28/90. Muestra métricas descriptivas, infraestructura y hasta 100 URLs.
- Config manual pendiente: `SEO_TECHNICAL_SITE_URL`, hosts adicionales, URLs
  estratégicas y sitemaps explícitos. No se inventaron valores. Pendiente de
  negocio: mapping verificable Stock -> URL pública y política de retención.
- Seguridad/rendimiento: límites de bodies/sitemaps, XML `LIBXML_NONET`, gzip
  acotado, cero dependencias nuevas, cero HTTP desde GET y ninguna transacción
  abierta durante red.
- Hardening final: Guzzle pasa de 7.10.0 a 7.15.3 y `composer.json` impide
  resolver `<7.15.2`. El guard exige hosts DNS ASCII canónicos y que todas las
  A/AAAA sean globales. El checker ignora proxies de entorno (`proxy=''`),
  mantiene TLS y `CURLOPT_RESOLVE`, y añade `read_timeout`.
- El streaming lee iterativamente hasta EOF/límite+1 y cierra en `finally`;
  `body_read_error` queda como finding aislado. Sobre HTML truncado solo se
  conservan señales positivas: noindex y canonical múltiple; las conclusiones
  negativas o de unicidad/coincidencia quedan no evaluables.
- Integridad de sitemap: el flag completo combina descubrimiento concluyente de
  robots y procesamiento íntegro de documentos. Un error/truncamiento de robots
  o una directiva `Sitemap:` bloqueada mantiene el scan parcial; los positivos
  hallados siguen siendo `true` y las ausencias quedan `null`.
- Hardening runtime final: Laravel 13.12.0, CommonMark 2.9.0, Symfony afectado
  8.0.15 e IDN polyfill 1.38.1 eliminan los 17 advisories restantes. Guzzle
  7.15.3 y PSR-7 2.13.0 se conservan; sus majors superiores quedan bloqueados
  por `composer.json`. CI ejecuta `composer audit --locked --no-dev` sin
  supresiones y producción debe instalar el lock, nunca actualizarlo.
- Archivos: configuración/env; comando, dos modelos y servicios
  `SeoTechnical*`; dos migraciones `2026_08_18_090000/090100`; integración
  acotada en dataset, vista y scheduler; cuatro tests SEO técnicos; documentación
  SEO/contexto/decisiones/operación. No se modificaron Stock, Search Console,
  GA4, Salesforce, CSS, JavaScript ni assets.
- Validación local de este cierre: sitemap 12 pruebas/40 aserciones,
  SeoTechnical 35/199, SEO 82/628 y suite completa 545/3.836, correctos. El
  presupuesto temporal ajeno de Stock falló en la primera ejecución, pero pasó
  aisladamente y en la repetición completa sin modificar Stock. Pint
  `--dirty --test`, Vite 8.0.12 y `git diff --check`, correctos. Vite no cambió
  `public/build`.
- Cambios de BD: migraciones aditivas pendientes de producción. El histórico no
  tiene todavía una retención aprobada y no debe purgarse por analogía. Acción
  manual: configurar/validar el origen, hosts, estratégicas y sitemaps; migrar,
  regenerar config cache y ejecutar la primera comprobación explícitamente.

Actualizado: 2026-08-19.

## SEO/Analytics Lote 3 - Conversiones web orgánicas GA4 (2026-08-17)

- Fix productivo de métricas: `Ga4MetricDecimalNormalizer` acepta decimal y
  notación científica `TYPE_FLOAT` mediante manipulación textual exacta. Los
  valores reales `2.6e-05` y `4e-06` persisten como `0.000026` y `0.000004`.
  No usa float; precisión real superior a seis decimales u overflow de
  `DECIMAL(18,6)` falla antes de la transacción y conserva datos anteriores.
- GA4 sincroniza exclusivamente `keyEvents` atribuidos a
  `defaultChannelGroup = Organic Search`, `platform = web`; España usa
  `countryId = ES`. Salesforce y GA4 siguen siendo métricas separadas y nunca se
  suman.
- Dos tablas nuevas conservan totales diarios ALL/ESP y detalle España por
  evento. `key_events` es `DECIMAL(18,6)`; los agregados cubiertos rellenan cero,
  pero el detalle no fabrica filas. El reemplazo del detalle ocurre dentro de la
  misma transacción que los upserts, después de completar las llamadas remotas.
- `seo:sync-ga4-organic --days=120` valida property, timezone, web streams y Key
  Events. Usa tres `runReport` habituales, paginación limit/offset y cutoff
  operativo con lag 2–7 días (default 3) en timezone de la property. Scheduler:
  05:45 Madrid, lock 120 y alerta técnica existente.
- `ReportSyncRun` guarda `stats.property_id` antes del primer HTTP y conserva
  estados por property. El dashboard incorpora GA4 al common cutoff, KPI, serie
  diaria, source status y breakdown limitado a 50 eventos; el GET continúa sin
  red.
- Seguridad: OAuth `analytics.readonly`, property y filtros solo desde config y
  código; no se consultan Measurement Protocol secrets ni se persisten tokens,
  credenciales o payloads raw. No hay dependencias nuevas ni cambios CSS/JS.
- Quality gate: cada página `runReport` se rechaza si GA4 declara thresholding,
  pérdida por fila `other` o sampling. La validación sucede antes de acumular
  filas y del zero filling; cualquier incidencia conserva todos los datos
  anteriores y usa el flujo de fallo técnico existente.
- Cambios de base de datos: dos migraciones aditivas crean exclusivamente los
  agregados diarios GA4 y su detalle diario por Key Event. No se ejecutaron
  migraciones ni sincronizaciones sobre producción.
- Acciones manuales: configurar `SEO_GA4_REPORTING_LAG_DAYS` (2–7, default 3),
  desplegar código y migraciones, refrescar la config cache, validar primero con
  `seo:diagnose-integrations --live` y ejecutar la primera
  `seo:sync-ga4-organic --days=120` solo tras aprobar la property. El riesgo
  residual principal es validar con datos reales que la taxonomía de Key Events
  y los valores `defaultChannelGroup`/`platform` coinciden con la property.
- Validación local tras el normalizador científico: normalizador 2 pruebas/34
  aserciones; sync GA4 11/119; GA4 20/178; SEO 85/679; suite completa
  550/3.921 (272,30 s). Pint `--dirty --test`, Vite 8.0.12,
  `composer audit --locked --no-dev` y `git diff --check`, correctos. El build no
  cambió `public/build` porque no existen cambios CSS/JS.

## SEO/Analytics Lote 2 - Search Console y Lead orgánico (2026-08-17)

- Tres tablas nuevas separan agregados diarios exactos Search Console,
  rankings top reemplazables y proyección diaria Salesforce. Las sync son
  idempotentes y los rankings solo se reemplazan después de completar todas las
  lecturas remotas.
- `seo:sync-search-console` determina cutoff final en timezone Los Ángeles,
  persiste ALL/ESP y brand/non-brand, y conserva rankings 7/28/90. La regex de
  marca procede únicamente de configuración.
- `seo:sync-salesforce-organic` consulta `Lead.Medio_origen__c = 'Orgánico'`,
  cuenta registros y agrupa `CreatedDate` en Madrid. No toca el mapping legacy
  `LEA_SEL_Medio_Origen__c -> salesforce_leads.medio_origen`.
- Scheduler: Search Console 05:15 y Salesforce 05:30, 120 días, zona Madrid,
  locks de 120 minutos y monitorización técnica existente.
- El dashboard usa solo BD local/config y acepta exclusivamente los rangos
  textuales 7/28/90. Cada KPI exige cobertura diaria completa acreditada por el
  último `ReportSyncRun` completado; runs fallidos/en curso posteriores conservan
  el último cutoff válido. Resumen/Tráfico usan periodo común y rankings el
  periodo propio de Search Console/property configurada.
- `ALL / all` y `ESP / all` son totales exactos. Marca/no marca son subconjuntos
  filtrados no exhaustivos: pueden sumar menos que España porque Search Console
  omite consultas anonimizadas al filtrar por query. No se fuerza conciliación ni
  se inventa un tercer segmento.
- El comando Search Console persiste `stats.property` al crear el run, antes de
  cualquier HTTP. Así los estados `running`/`failed` se atribuyen a su property
  sin guardar credenciales; completion sustituye esos stats por el resumen
  completo. Los contenedores de tablas SEO focalizables tienen nombre accesible
  y sus encabezados de columna declaran `scope="col"`.
- Deploy requiere las tres migraciones, config cache y primera sync manual por
  fuente solo después de validar configuración. No hay dependencias nuevas.
- Seguridad: credenciales solo en entorno, OAuth/SOQL read-only, errores
  sanitizados, inputs web whitelisteados y escaping Blade. Rendimiento: 14
  consultas Search Analytics acotadas por sync, una consulta Salesforce
  paginada por ventana y render exclusivamente sobre índices/BD local.
- Validación final: SEO 29 pruebas/268 aserciones; regresión del mapping
  Salesforce 2/17; suite completa 492/3.476. Pint
  `--dirty --test`, Vite 8.0.12 y `git diff --check`, correctos. El build no
  rotó assets porque no cambiaron fuentes CSS/JS.

## SEO/Analytics Lote 1 - fundamento de integraciones (2026-08-17)

- Configuración independiente para Search Console, GA4 y SISTRIX; Salesforce
  reutiliza `SalesforceClient::describe()`. No se modifica Google Ads.
- Clientes nuevos son exclusivamente read-only y usan Laravel Http, timeouts y
  `IntegrationErrorSanitizer`. Search Console lista sites; GA4 verifica property,
  metadata, timezone y Key Events paginados; SISTRIX solo llama `credits`.
- `seo:diagnose-integrations` es config-only por defecto. `--live` realiza las
  lecturas externas y aísla fallos por fuente sin imprimir credenciales, tokens,
  respuestas OAuth completas ni saldo SISTRIX.
- La pantalla muestra cuatro estados neutrales desde configuración local. No
  ejecuta HTTP externo, no afirma acceso verificado y conserva el mensaje de que
  todavía no existen métricas.
- No hay migraciones, tablas, scheduler, jobs, KPI, snapshots o alertas SEO. El
  contrato operativo y futuro está en `docs/ai/SEO_ANALYTICS.md`.
- Diagnóstico real read-only del 2026-08-17: Salesforce verificó
  `Medio_origen__c` (`Medio de origen`, picklist) como único candidato con el
  valor exacto `Orgánico`; `LEA_SEL_Medio_Origen__c` ofrece `Organic`. Search
  Console, GA4 y SISTRIX estaban sin configurar y no recibieron llamadas.
- Acciones manuales: completar variables de entorno cuando existan credenciales,
  refrescar config cache y ejecutar el diagnóstico live desde CLI. Nunca usar el
  resultado config-only como acreditación de acceso.
- Validación local final: filtro `Seo` (12 tests, 113 assertions), navegación
  estratégica (6 tests, 84 assertions), suite completa (475 tests, 3.321
  assertions), Pint `--dirty`, Vite 8.0.12 y `git diff --check`, correctos.

## Lote 1 Fase 1 - Application shell y navegacion estrategica (2026-08-13)

- Se incorpora `x-reports.app-shell` a Leads, Reservas/Ventas, Llamadas,
  Campanas, Stock, Comisiones y las paginas administrativas existentes. No se
  modifican datasets, endpoints, filtros, KPIs, reglas, exports ni integraciones.
- La sidebar agrupa Resumen; Comercial; Marketing; Operaciones; Comisiones; y
  Administracion. Solo renderiza rutas autorizadas. En escritorio puede
  ocultarse por completo para liberar ancho; en movil funciona como drawer con
  overlay, Escape y gestion de foco. El estado de escritorio usa la key local
  `hrmotor-report-sidebar` bajo `try/catch`, sin BD ni API.
- Resumen (`reports.index`) y SEO y Analytics
  (`reports.seo-analytics.index`) son pantallas estructurales sin consultas,
  metricas ficticias ni llamadas externas. Solo Administrador y Director
  acceden. La politica vive fuera de las definiciones editables y no puede
  rebajarse mediante `report_access_settings`.
- Para perfiles no estrategicos, `/informes` y el login siguen resolviendo el
  primer informe operativo permitido. Director no hereda paginas exclusivas de
  Administrador. Marketing no recibe SEO y Analytics.
- `ReportUserAccess` memoiza los minimos y las claves visibles en atributos del
  request, evitando consultar la configuracion una vez por enlace. No se usa
  estado estatico mutable ni se cargan datos de dashboards desde la navegacion.
- Assets nuevos: `resources/css/reports/app-shell.css` y
  `resources/js/reports/app-shell.js`, compilados por Vite. No hay dependencias,
  migraciones ni variables de entorno nuevas. Los artefactos de `public/build`
  deben desplegarse junto al codigo porque produccion no dispone de Node.
- El shell define `border-box` de forma acotada para su arbol y
  pseudo-elementos. Su geometria no depende del reset global de ningun
  dashboard, por lo que Resumen y SEO conservan la misma densidad de sidebar.
- Validacion focal previa al cierre: 24 pruebas de shell/acceso/login (189
  aserciones) y 99 pruebas de dashboards complejos (857 aserciones), todas
  correctas. Consultar la entrega de la tarea para suite completa, Pint, build
  y diff-check finales.

## Lote 2 Fase 1 - Design System compartido (2026-08-14)

- `resources/css/reports/design-system.css` introduce tokens
  `--report-ui-*` y primitives `report-ui-*` para cabeceras, superficies,
  botones, badges, formularios, empty states, skeletons y tablas. No contiene
  selectores globales legacy, `!important`, JavaScript ni dependencias nuevas.
- Controles y superficies nuevos usan radio discreto de 8 px. El radio pill se
  reserva a badges, estados y futuros chips; no se aplica globalmente al CSS
  legacy. El logout del shell consume el radio de control.
- El shell carga Design System antes de su CSS y conserva apariencia y
  comportamiento mediante aliases. `--app-sidebar-width` y
  `--app-topbar-height` siguen perteneciendo al shell. `updatedBadge` mantiene
  ID y clase `badge` junto al nuevo primitive.
- Componentes Blade nuevos: `x-reports.ui.page-header`,
  `x-reports.ui.empty-state` y `x-reports.ui.status`. No consultan datos y usan
  el escape estándar. Un estado desconocido cae en `not-evaluable`.
- Solo Resumen y SEO/Analytics adoptan los primitives; mantienen sus mensajes
  estructurales sin KPI, estados analíticos ni integraciones ficticias. Los seis
  dashboards y sus vistas internas no se migran en este lote.
- Contrato operativo: `docs/ai/DESIGN_SYSTEM.md`. No hay migraciones, cambios de
  autorización, endpoints, consultas ni configuración sensible.
- Verificación focal: Design System 5 pruebas/53 aserciones y navegación
  estratégica 6/84. Suite completa 458 pruebas/3.205 aserciones, Pint sobre PHP
  modificado y build Vite correctos. Consultar la entrega del lote para hashes
  finales.

## Lote 3 Fase 1 - Analytical UI Patterns (2026-08-14)

- El bundle existente `design-system.css` incorpora KPI strip continuo, data
  panels, section headers, tablas densas, tabs lineales, filter bar, row
  highlighting neutral y source status. No hay bundle, dependencia o JavaScript
  adicional.
- Componentes Blade nuevos: `x-reports.ui.section-header` y
  `x-reports.ui.source-status`; son presentacionales, escapan props y no leen
  request, sesión, BD o servicios. Source status no define estados funcionales.
- KPI items no son cards; tabs no son pills; sticky de tabla es opt-in; highlight
  significa énfasis y no estado. La identidad continúa siendo HR Motor y la
  referencia externa se usa solo para estructura y densidad.
- El cierre de revisión usa `--report-ui-text-muted: #5f6b7d` y
  `--report-ui-control-border: #8793a5` para cumplir contraste WCAG AA de texto
  secundario y límites de controles sin crear overrides por componente.
- Resumen/SEO no reciben datos, KPI, tablas, fuentes, tabs o estados ficticios.
  Los seis dashboards y CSS legacy permanecen sin migrar.
- Contratos y accesibilidad quedan documentados en `docs/ai/DESIGN_SYSTEM.md`.
  No hay cambios funcionales, rutas, autorización, queries o migraciones.
- Validación local: `ReportAnalyticalPatternsTest` (5 tests, 46 assertions),
  `ReportDesignSystemTest` (5 tests, 53 assertions),
  `StrategicReportNavigationTest` (6 tests, 84 assertions), suite completa
  (463 tests, 3.251 assertions), Pint `--dirty` y build Vite 8.0.12, correctos.
  El build sustituye únicamente el asset compilado del Design System y actualiza
  su referencia en `manifest.json`; no requiere acciones manuales distintas del
  despliegue habitual de assets versionados.

## Lote de cold-path: AI Leads y caches de Llamadas (2026-08-13)

- Leads devuelve inmediatamente el fallback de insights cuando no existe un
  resultado AI válido en caché. El refresco se difiere hasta después de enviar
  la respuesta (`defer()`), por lo que no requiere un worker permanente; si no
  llega a ejecutarse, el dashboard sigue devolviendo el mismo fallback. Un
  resultado AI cacheado mantiene `source=ai` sin red. Fallos de conexión,
  timeout, HTTP no exitoso o respuesta inválida abren un cooldown cacheado y
  evitan reintentos lentos durante `REPORT_AI_COOLDOWN_SECONDS` (60 por
  defecto). No se registran prompts, payloads ni secretos.
- La sincronización real de Llamadas (`SalesforceCallSyncService::sync()` vía
  `salesforce:sync-calls`) incrementa la versión de datos al finalizar. El
  comando intenta precalentar entonces, y de forma no crítica, los filtros
  `calls-dashboard:filters:<md5(version)>`; un error de precarga solo deja el
  fallback existente de construcción bajo demanda y se registra sin payload.
- `summary` y `/agents` comparten `calls-dashboard:shared:agents:<hash>`.
  El hash contiene filtros (incluyendo scope), periodo actual y data version;
  usa lock Laravel con espera acotada y fallback compatible si el store no
  soporta locks. TTL 10 minutos y versión aseguran invalidación en sync. No se
  comparte el agregado de delegaciones: rankings excluye etiquetas operativas
  inválidas y usa métricas diferentes de `/delegations`.
- Server-Timing permanece: distingue AI cache/fallback/refresh, filters hit/miss
  y agents shared hit/miss. No hay migraciones, índices, cambios de SQL, TTL
  del payload principal, frontend ni reglas funcionales.

## Diagnóstico temporal Server-Timing de Leads y Llamadas (2026-08-12)

- Se añade `REPORT_SERVER_TIMING=false` por defecto. Cuando se habilita, solo
  un administrador puede recibir la cabecera estándar `Server-Timing` en
  Leads summary y en Llamadas summary, agents y delegations. Se reutiliza
  `ReportUserAccess::canSeeSyncDiagnostics()`: no se amplían permisos de
  auditoría ni se expone el diagnóstico a roles funcionales.
- `App\Support\ReportServerTiming` es efímero por request y solo mide nombres
  técnicos fijos y milisegundos con `hrtime(true)`. No usa consultas extra,
  listeners, query log, logs, sesión adicional ni persistencia. El callback de
  `Cache::remember()` marca el miss sin una lectura extra de caché; un hit solo
  informa del hit y del total, nunca de bloques internos no ejecutados.
- Métricas: Leads separa current, previous, groups, finalize, filters e
  insights. Calls summary separa current, previous, agents/teams, portals,
  daily, ranking, reconciliation, metadata y filters; agents y delegations
  separan query y finalize. La respuesta JSON y sus claves de caché no cambian.
- No hay migraciones ni cambios de SQL, índices, TTL, permisos de informe ni
  reglas funcionales. Para activarlo temporalmente en producción: establecer
  `REPORT_SERVER_TIMING=true`, ejecutar `php artisan config:clear` y
  `php artisan config:cache`; después repetir con `false` y los mismos
  comandos. No requiere `APP_DEBUG=true`.
- Prueba focalizada: `ReportServerTimingTest` cubre flag apagado, administrador,
  usuario no administrador, miss/hit de los cuatro endpoints, sintaxis de
  cabecera, equivalencia de JSON, aislamiento de instancia y excepción medida
  sin alterar su propagación.

## Lote 2: plan temporal de Leads y Llamadas (2026-08-12)

- Se restaura en Leads la lectura independiente de periodo actual y comparado.
  El plan productivo elegÃ­a el Ã­ndice booleano de baja cardinalidad para ambas
  variantes y la consulta conjunta con `OR` no mejorÃ³ latencia; no se aÃ±ade
  Ã­ndice de Leads sin un plan adicional que justifique el orden de columnas.
- Se aÃ±ade `sf_calls_dashboard_created_idx` sobre
  `salesforce_calls(included_in_dashboard, created_date)`. Atiende el predicado
  compartido por los agregados del dashboard; no cubre ni intenta optimizar
  expresiones de equipo, zona, delegaciÃ³n o portal.
- La migraciÃ³n es aditiva. Su `down()` elimina solo ese Ã­ndice. En producciÃ³n,
  programar la creaciÃ³n en una ventana de bajo trÃ¡fico y verificar espacio libre
  e impacto sobre sincronizaciones antes de ejecutarla.
- Las pruebas cubren frontera de periodos de Leads, consistencia cache miss/hit,
  conciliaciÃ³n existente de Llamadas y creaciÃ³n/eliminaciÃ³n aislada del Ã­ndice
  en SQLite. El plan SQLite no sustituye `EXPLAIN` de MySQL 8.0 de producciÃ³n.

## Lote 1: cache miss de Leads y Llamadas (2026-08-12)

- Leads reutiliza una sola lectura por lotes para los periodos actual y comparado
  del payload compartido. Conserva la decoraciÃ³n con la fecha de referencia de
  cada periodo y la misma construcciÃ³n de KPIs, filtros, agrupaciones e
  insights.
- Llamadas consolida las cuatro consultas de conciliaciÃ³n del resumen en un
  agregado equivalente. TambiÃ©n reutiliza la agregaciÃ³n de agentes para los
  equipos y obtiene zonas/delegaciones desde un Ãºnico agrupado por pareja.
- No se modifican TTL, scopes, rutas, contratos HTTP, Ã­ndices, migraciones ni
  reglas de clasificaciÃ³n. No se aÃ±ade instrumentaciÃ³n persistente.
- Se aÃ±aden pruebas de equivalencia cache miss/cache hit para Leads y para los
  endpoints summary, agents y delegations de Llamadas. Las pruebas existentes
  cubren filtros, Ã¡mbitos, comparativas y reconciliaciÃ³n.
- Riesgo pendiente: validar en producciÃ³n el impacto real con las mismas
  combinaciones de periodo; el baseline local no representa cardinalidad ni
  planes de MySQL 8.0 de producciÃ³n.

## CI: aislamiento de Google Ads en CampaignDashboardTest (2026-08-12)

- `test_google_ads_inventario_auditable_consulta_anuncio_y_ad_group` ya declara
  el `customer_ids` sintÃ©tico que exige `GoogleAdsClient::configured()`, junto
  con sus otros valores sintÃ©ticos y `Http::fake`.
- El test no realiza red ni usa credenciales reales. `GoogleAdsClient` conserva
  el fallo seguro `Google Ads no configurado.` fuera de ese contexto de prueba.
- Focalizado: 41 tests, 406 aserciones. Suite completa: 436 tests, 2.951
  aserciones. Build, Pint del test y `git diff --check`: correctos.
- Sin migraciones, configuraciÃ³n productiva ni cambios funcionales de CampaÃ±as.

## Hardening transversal final

### Resumen

- Se añadió CI real en `.github/workflows/ci.yml`, sin secretos ni permisos de
  escritura: PHP 8.4, Composer, migraciones SQLite, tests, Pint, Node 22/build y
  whitespace check.
- Login: throttling configurable, mensaje uniforme, coste de hash también para
  usuario inexistente, regeneración de sesión y cookie remember segura según
  configuración. Producción activa Secure y cifrado de sesión por defecto.
- API Comisiones: Basic compatible con credenciales múltiples/versionadas en
  entorno, revocación, comparación sobre hashes de longitud fija, rate limits de
  auth y tráfico por integración, `429` y auditoría diaria con request ID.
- Logs: daily por defecto, 90 días y procesador central de redacción.
- Retención: comando chunked/dry-run para ocho payloads crudos sin consumidores,
  sync runs, colas y alertas resueltas. Cinco payloads con lecturas funcionales
  quedan bloqueados hasta materializar dependencias; no se fuerzan borrados.
- Alertas: tabla/modelo/servicio deduplicado, panel paginado solo admin y
  callbacks de scheduler. Stock conserva su criterio, pero deja de enviar email
  y de escribir Tasks Salesforce.
- Autorización sensible de usuarios, permisos, fórmulas y penalizaciones devuelve
  `403` en acceso directo no autorizado.
- `DatabaseSeeder` ya no crea una identidad corporativa ni datos demo fuera de
  `local/testing`; se retiró el email fallback legacy de alertas de Stock.
- Despliegue documentado sin Node y sin rollback genérico destructivo.

### Archivos principales modificados

- `bootstrap/app.php`, `routes/api.php`, `routes/web.php`, `routes/console.php`.
- Middlewares de API, login/sesión y controladores administrativos.
- `config/auth.php`, `logging.php`, `services.php`, `session.php`, `.env.example`.
- `OperationalAlert`, `OperationalAlertService`, panel administrativo y servicio
  de alertas de Stock.
- `PruneTransversalDataCommand` y migración
  `2026_08_11_130000_create_operational_alerts_and_retention_indexes.php`.
- Tests focalizados de API, auth, permisos, logs, alertas y retención.
- README, runbook de producción, contexto y decisiones AI.

### Decisiones y base de datos

- Secrets en environment, no en BD; legacy se retira solo tras identificar al
  consumidor y completar rotación.
- La migración es aditiva: crea `operational_alerts` y 11 índices de retención
  sobre tablas existentes. No contiene data migration ni ejecuta pruning.
- El `down()` de la tabla de alertas es destructivo una vez utilizada; preferir
  rollback de código/forward fix. Los índices sobre tablas grandes pueden
  consumir tiempo y bloquear DDL en MariaDB 10.6: medir sobre copia.

### Seguridad y rendimiento

- Auditoría API no registra headers, body, IP ni query completa.
- Alertas y errores persistidos se sanitizan y truncan.
- Panel con filtros servidor y 25 filas; alertas deduplicadas por unique
  fingerprint. Pruning usa índices, lotes y transacción por lote.
- Cachés revisadas: TTL 10 minutos e inclusión de ámbitos/filtros donde aplica.
  La evaluación de Stock pasó de consultar alertas abiertas por delegación a
  una carga única; no se añadieron cambios funcionales ni N+1.

### Pruebas ejecutadas

- Baseline previo: la suite completa agotó 300 segundos sin resumen; no llegó a
  comunicar fallos de aserción. El historial anterior registraba 423 tests.
- Sintaxis de PHP modificado: correcta.
- `migrate:fresh --env=testing --force`: correcto, incluida la nueva migración.
- Rutas API y `schedule:list`: correctos.
- Focalizadas iniciales: 29 tests, 166 aserciones, correctas tras actualizar una
  expectativa histórica de redirect a `403`.
- Focalizadas ampliadas: 40 tests, 213 aserciones, correctas.
- Suite completa final: 436 tests, 2.951 aserciones, correcta en 390,3 s con
  `max_execution_time=900`. El intento inmediatamente anterior con el límite
  local de 120 s terminó por timeout del runner en Symfony Finder, sin fallo de
  aserción; no es un error funcional del lote.
- `npm run build`: correcto; no cambió artefactos versionados. Conserva avisos
  no bloqueantes sobre `login-bg.jpg` resuelto en runtime y una API Node obsoleta.
- Composer `validate --strict --no-check-publish`: correcto.
- Workflow `.github/workflows/ci.yml`: sintaxis correcta con `yaml-lint`.
- Pint global: no correcto por deuda histórica ajena al lote. Pint sobre todos
  los PHP modificados/nuevos: correcto.
- `git diff --check`: correcto en la revisión final.

### Acciones manuales y riesgos

- Seguir `docs/operaciones-produccion.md`; no ejecutar npm en producción.
- Verificar backup restaurable, cuota de logs, locking/tiempo de índices y dueño
  de cada consumidor API.
- Configurar en cPanel límites de proceso/monitorización de cron; los locks de
  Laravel no terminan procesos y solo deben limpiarse tras confirmar que no hay
  una ejecución activa.
- Configurar/rotar credenciales fuera del repo; Codex no lo ha hecho.
- No se ejecutaron deploy, push, migración/pruning/job real ni llamadas externas.

## Cierres independientes de Comisiones Comerciales

- Los cierres pasan a ser independientes por `month + closure_scope` para
  Comerciales, Delegaciones y Área Manager. Cada scope conserva snapshots y
  eventos propios; Contact Center, Call Center y Financieros no se congelan.
- La migración mantiene los cierres existentes como `legacy` y no presupone que
  representen aprobación funcional de los nuevos bloques.
- No se verificaron en el repositorio los Salesforce User IDs de las excepciones
  Oscar, Nuria e Irene. No se mantienen reglas económicas por nombre.
- La lectura definitiva de Área Manager reutiliza `area_manager` o
  `area_manager_by_zone` del snapshot según el rol. Está cubierta para Dirección
  y para Área Manager restringido; los cambios posteriores de fuente no alteran
  el bloque congelado.

## Correctivo Meta Direct Form sin `campaign_acquired`

- `CampaignAttributionBuilderService` conserva como candidatos los Leads Meta Direct Form identificados por portal/origen aunque `campaign_acquired` sea nulo, vacío o inválido. No incrementan `discarded_invalid_values`.
- Los IDs originales mantienen su precedencia: un match publicitario inequívoco gana antes del fallback Meta. Un Lead no Meta sin campaña válida sigue descartándose.
- No hay cambios de esquema, migraciones ni escrituras de reproceso en este correctivo.

## Resumen de la tarea

Correctivo focalizado de las exportaciones de auditoría de Llamadas y
Reservas/Ventas. Se corrigió la cardinalidad y serialización del CSV de
Llamadas, la conciliación visible de atendidas por equipo, la unicidad de la
cohorte de Reservas/Ventas y la minimización de datos personales.

## Archivos modificados

- `app/Support/CsvValueSerializer.php`.
- Controladores y datasets de Llamadas y Reservas/Ventas.
- `app/Console/Commands/DebugReservasVentasReportCommand.php`.
- Pruebas feature de ambos informes y de ámbitos de exportación.
- Documentación específica y handoff del proyecto.

## Decisiones adoptadas

- El CSV de Llamadas usa columnas fijas, cursor y serialización explícita de
  valores estructurados.
- El KPI y la auditoría de Reservas/Ventas consumen una única función de
  resolución de cohorte.
- `Opportunity.Name` y los datos de contacto de Account no forman parte de la
  exportación estándar de auditoría.
- Se conserva la matriz vigente de acceso; los ámbitos se aplican en servidor.

## Cambios de base de datos

Ninguno. No hay migraciones ni backfill.

## Seguridad

Se retiraron del flujo estándar de auditoría el nombre potencialmente personal
de Opportunity, nombre de Account, teléfonos y correos del cliente. El comando
de conciliación solo muestra IDs técnicos y cantidades.

## Rendimiento

Llamadas se exporta mediante cursor y sin consultas por Task. Reservas/Ventas
selecciona únicamente las columnas necesarias y resuelve cada Opportunity una
vez por construcción del dataset, en lotes de 1.000 y sin consultas por fila.

## Pruebas ejecutadas

- Lint PHP: correcto.
- Pruebas nuevas/focalizadas: 11 tests, 83 aserciones, correctas.
- `--filter=CallDashboard`: 5 tests, 40 aserciones, correcto.
- `--filter=ReservationsSales`: 3 tests, 38 aserciones, correcto.
- `--filter=OpportunityDashboard`: 3 tests, 35 aserciones, correcto.
- `--filter=ReportAccess`: 8 tests, 55 aserciones, correcto.
- Regresión focalizada final: 23 tests, 166 aserciones, correcta.
- Suite completa, primer intento: recorrió 405 tests; 403 pasaron y detectó dos
  regresiones de compatibilidad en agentes, corregidas y verificadas después.
- Suite completa, reintentos finales: bloqueados por timeout de 120 segundos en
  un handler HTTP de Guzzle antes de emitir resumen; no fue un fallo de
  aserción del lote.
- Laravel Pint: correcto sobre todos los PHP modificados.
- `git diff --check`: correcto.
- `npm run build`: correcto; Vite finalizó en 12,06 segundos con advertencias no
  bloqueantes indicadas por el propio build.

## Acciones manuales necesarias

- Desplegar código y limpiar cachés de Laravel.
- Ejecutar en el entorno con la fotografía observada el diagnóstico de cohorte
  documentado, si se necesitan conocer los IDs de producción.

## Riesgos o pendientes

- Los cuatro Opportunity IDs observados no están disponibles en los datos
  locales; no se han inventado ni documentado.
- No se ha implementado ningún cambio de reglas de negocio ni reproceso.

## P1 Leads y Reservas/Ventas (2026-08-07)

- `LeadRecordTypeNormalizer` normaliza Lead y Ayvens como Venta. El comando
  `reports:reprocess-lead-record-types` permite alinear el histórico
  materializado con `--dry-run`, por lotes e idempotencia.
- Campañas usa una fotografía propia de tipos normalizados; no se reconstruye
  automáticamente desde este lote.
- El campo funcional Salesforce “Delegación” no tiene API Name verificable en
  el repositorio. No se utiliza ni se inventa; este es un bloqueo documentado.
- Reservas/Ventas deja de depender de `ReservationsSalesAiInsightsService`.
  El servicio se conserva sin cambios para evitar afectar consumidores ajenos.
- Verificación P1: `--filter=Lead` correcto (121 tests, 617 aserciones),
  pruebas nuevas focalizadas correctas (17 tests, 124 aserciones), suite completa
  correcta (409 tests, 2.777 aserciones), Pint y build frontend correctos.

## Corrección de fallback Exposición (2026-08-07)

- El fallback de delegación por owner/persona trabajadora usa exclusivamente el
  tipo funcional normalizado `exposicion`; el portal no participa en esa decisión.
- Se conservan las prioridades de campos y el fallback histórico persistido.
- Verificación: delegación 4 tests/16 aserciones, Leads 121/622, suite completa
  409/2.782, build y Pint correctos.

## P1 Llamadas (2026-08-07)

- Se reutilizan `included_in_dashboard` y `dashboard_exclusion_reason` para
  excluir el perfil Salesforce exacto de pruebas sin retirar Tasks de auditoría.
- `CallClassificationRules` centraliza ajuste 5/10, exclusión de perfil y el
  valor canónico `unassigned`. No hay cambios de esquema.
- Sync y reproceso resuelven el perfil desde `SalesforceUser` por el ID operativo
  cuando está disponible; `missing_call_object` prevalece.
## P1 Campañas: cierre de inversión (2026-08-07)

- Se añadieron cierres, snapshots y eventos de Campañas. Solo Administrador/IT
  puede cerrar/reabrir; snapshots no se borran en cascada.
- El dashboard usa inversión congelada para meses cerrados y deja abiertos los
  resultados comerciales. Ambigüedades y exclusiones permanecen auditables.
## Correctivo Salesforce-only de Campañas (2026-08-07)

- El dataset representa como `null` las métricas comerciales/económicas de
  Salesforce-only y evita que entren en los totales.
- La auditoría de exclusiones incorpora motivo, mecanismo `exact_name` y valor.
- No hay migración adicional ni reproceso ejecutado.
## Simulación histórica de Campañas (2026-08-07)

- `salesforce:sync-campaign-leads --dry-run` lee Salesforce sin borrar,
  upsertear ni invalidar caché.
- `campaigns:build-attribution --dry-run` ejecuta el mismo builder en memoria y
  compara la simulación con `campaign_attributions`, sin escritura.
- La reconstrucción histórica en escritura mediante `--from` requiere
  `--reason`. No se ejecutó ningún reproceso.
## Correctivo de dry-run de tipos nulos (2026-08-07)

- La simulación de atribución usa la etiqueta técnica `null` para estadísticas
  cuando el normalizador no devuelve tipo; el Lead sigue en el universo.
- El comando de reproceso desglosa cambios no Lead/Ayvens por raw, valor
  materializado y valor calculado, con muestras de IDs no personales.
## Diagnóstico de cambios de atribución (2026-08-07)

- El dry-run separa identidad de campaña (`platform + campaign_id`, o nombre
  normalizado sin ID) de cambio de método y muestra transiciones agregadas.
- Valores inválidos y campañas excluidas se contabilizan por separado; las
  exclusiones exactas informan motivo y muestra de IDs.

## Excepciones personales de Financieros (2026-08-11)

- La excepción del 0,50 % desde `2026-06` para Nuria e Irene se configura en
  `config/commercial_commissions.php` por Salesforce User ID y se aplica al
  `owner_id` ya sincronizado en `salesforce_opportunities`.
- El resultado especial sustituye los tres bloques normales; no usa nombre,
  zona ni email. Las reglas temporales editables por zona se retiraron.
- La regla histórica del 40 % atribuida a Oscar no está en la especificación
  vigente y sigue desactivada; no se agregó ninguna identidad ni fila sintética.
- No hay migraciones, llamadas Salesforce ni cambios de universos. Financieros
  continúa siendo operativo y no se congela mediante cierres de comisiones.
- Verificación: `CommercialCommissionDashboardTest` (44/291), `CommercialCommission`
  (56/345), `financieros` (6/38), ámbito Área Manager (1/4), cierres (9/72) y
  suite completa (423/2.883) correctos. Build correcto; Pint correcto para los
  PHP modificados. Pint global conserva deuda preexistente fuera de este lote.

## Auditoría y correctivos de Stock (2026-08-11)

- Se validó el universo Product2 y el scheduler: Disponible, Reservado y
  Bloqueado ocupan capacidad; solo Disponible entra en recomendaciones. El
  plan usa capacidad virtual y no escribe movimientos.
- Se añadió `missing_signed_date` a la validez de ventas y se mantiene la
  resolución existente de duplicados por última firma sin desempatar con
  `LastModifiedDate`.
- Se incorporó la fecha de matriculación verificada por describe de Salesforce:
  `PRO_DAT_Fecha_de_matriculacion__c` → `salesforce_vehicles.registration_date`.
  No se modificaron pesos de ranking, al no existir fórmula aprobada.
- Los aliases de catálogo requieren permiso independiente
  `stock.catalog_aliases.approve`, target Product2 activo y auditoría de
  aprobador/fecha/motivo. Los aliases históricos quedan sin efecto como
  `legacy_unverified` hasta revisión; no constituyen catálogo alternativo.
- `StockDailySnapshotService` ahora reemplaza transaccionalmente la fotografía
  de la fecha para evitar filas obsoletas en reintentos.
- Archivos principales: modelos `ReportUser`, `StockCatalogAlias`,
  `SalesforceVehicle`; servicios de Stock; controlador/route de aprobación;
  migraciones `2026_08_11_120000` a `122000`; pruebas de Stock y documentación.
- No hubo llamadas Salesforce de escritura ni operaciones de stock. Bloqueo:
  falta una definición verificable de los estados que identifican logística
  activa; no se añadió una inferencia que pueda excluir o puntuar vehículos.

## Cierre de revisión del Application Shell (2026-08-13)

- El drawer móvil aplica `inert` únicamente al workspace mientras está abierto,
  ofrece cierre dentro de la navegación y restaura el foco al control de apertura
  al cerrar mediante Escape, overlay o botón. El cambio de breakpoint siempre
  retira `inert`; la persistencia desktop en `localStorage` se conserva.
- La topbar continúa siendo sticky. Los selectores sticky de Comerciales, Call
  Center y Contact Center usan un offset basado en `--app-topbar-height`.
- El fondo neutral prevalece en páginas `body.campaigns-report`. El enlace activo
  usa el rojo accesible `#a50f23` y el foco visible el anillo `#8f1020`; no se ha
  añadido `!important`. Penalizaciones dispone de icono propio.
- Se verificó expresamente que una fila histórica `summary=viewer` no concede el
  Resumen ni altera el landing operativo de viewer. La protección fija de
  SEO/Analytics permanece cubierta.
- No hay migraciones, dependencias, requests ni cambios funcionales en informes.
  Verificación: `StrategicReportNavigationTest` 6 pruebas/83 aserciones, suite
  completa 453/3.151, Pint sobre PHP modificado, build Vite y
  `git diff --check` correctos.
