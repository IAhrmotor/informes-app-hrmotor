# Handoff para agentes

Actualizado: 2026-08-13.

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
- Validacion focal previa al cierre: 24 pruebas de shell/acceso/login (189
  aserciones) y 99 pruebas de dashboards complejos (857 aserciones), todas
  correctas. Consultar la entrega de la tarea para suite completa, Pint, build
  y diff-check finales.

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
