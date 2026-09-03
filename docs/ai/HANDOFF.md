# Handoff para agentes

## Segunda iteración de Rendimiento comercial (2026-08-27)

- Bootstrap: `CommercialDelegationSnapshotService` materializa, de forma
  idempotente, un intervalo cerrado desde `2026-04-01 00:00 Europe/Madrid` hasta
  la primera observación fiable. Usa `business_bootstrap_2026_04`, no modifica
  observaciones reales y excluye IDs sin dimensiones o con historia previa
  contradictoria; este último caso abre `commercial_bootstrap_conflict` para
  conservar la incidencia. La réplica local inspeccionada tenía 0 snapshots, por lo que
  no existían usuarios locales bootstrapables ni IDs que clasificar sin ejecutar
  el sync Salesforce (prohibido en esta tarea). `captureCurrentUsers()` ya no
  invoca esta operación: el comando solo la ejecuta tras la captura observada
  cuando recibe `--bootstrap-performance-history`. El scheduler cada 15 minutos
  conserva `--days=2` sin la opción, por lo que altas posteriores no se
  retroatribuyen a abril. La reejecución explícita también está protegida: la
  cohorte inicial exige que el primer snapshot observado del usuario coincida
  exactamente con el mínimo `observed_from` global. Los usuarios posteriores
  se devuelven en `not_initial_cohort`; una observación inicial incompleta no se
  rellena usando otra posterior.
- Organización: bootstrap y observación son asignaciones evaluables y quedan
  diferenciados en `delegation_status`; los huecos/cambios son
  `not_certifiable`. Un cambio observado cierra/abre intervalos y genera una
  alerta `commercial_organisation_change` de severidad `low`, deduplicada por
  ID técnico, instante y dimensiones. Un cambio dentro del mes conserva la fila
  individual, excluye ranking y muestra una nota ámbar.
- Auditoría/calidad: cada evento conserva `delegation_certified` por
  compatibilidad y añade `delegation_status` (`observed`,
  `bootstrap_approved`, `not_certifiable`) y `delegation_issue`. La UI muestra
  Observada, Bootstrap aprobado o No certificable. El payload separa las fechas
  `delegation_history_evaluable_from`, `delegation_history_observed_from` y
  `delegation_history_bootstrap_from`; el nombre legacy se conserva sin usarlo
  para presentar el bootstrap como certificación Salesforce.
- Filtros: Comercial parte de todas las identidades comerciales válidas del mes,
  incluso sin delegación evaluable. Zona/Delegación solo parten de observación o
  bootstrap. No cambian los controles compartidos ni los datasets legacy.
- Cancelaciones: la inspección local contó 382 transiciones (291 `valid`, 80
  `opportunity_not_local`, 7 `reservation_not_demonstrated`, 3
  `reservation_after_transition` y 1 `previous_stage_not_demonstrated`). Había
  cuatro intervalos no certificados con 81 dependencias y uno certificado. La
  UI muestra `N/D` para null y reserva 0 a períodos cubiertos.
- Resolución por ID: History solicita solo Opportunities ausentes en chunks de
  100 mediante `SalesforceOpportunitySyncService::syncBySalesforceIds()`, que
  comparte SELECT, retry del email opcional, resolución de Lead y persistencia
  canónica. Recarga y reclasifica en el mismo run; los IDs no devueltos siguen
  como dependencia auditable.
- UI: se retiraron todas las medias de delegación; 11 columnas son visibles por
  defecto y Semáforo/Comercial son obligatorias. Las preferencias opcionales
  usan `reservationsSalesCommercialPerformanceColumnsV1`. Margen incluye ayuda,
  Evolución localiza el mes al español, los avisos separan información/calidad/
  error, la auditoría crea su tabla solo tras carga manual y se invalida al
  cambiar filtros. Las tres tablas anchas sincronizan scroll superior e inferior.
- Base de datos/configuración: sin migraciones, backfills, Salesforce ni cambios
  de entorno. No se alteró ningún snapshot local.
- Seguridad/rendimiento: no hay PII de cliente en alertas, IDs SOQL escapados,
  lotes acotados, ninguna red desde HTTP y ninguna petición nueva al cambiar
  columnas. Se mantienen autorización, CSRF y contratos existentes.
- Validación final de cohorte: snapshots/roster/comando 10/10 (74 aserciones);
  Rendimiento/CampaignCommands/Opportunity/History 61/61 (470); consumidores
  transversales de Leads, Comisiones, Area Manager y Llamadas 66/66 (474);
  suite completa 700/700 (5.267). Pint correcto y limitado a los cuatro PHP
  modificados en este correctivo. `composer audit --locked --no-dev` sin
  advisories. No se repitió Vite porque no cambió frontend: el último build
  aprobado permanece vigente y el manifest referencia únicamente los bundles
  `reservations-sales-dashboard-DX5Bsl6G.css` y
  `reservations-sales-dashboard-BXx1OBp9.js`. Los dos anteriores del módulo se
  eliminaron y los bundles de módulos ajenos generados por Vite se restauraron.
  La validación local en navegador confirmó la carga lazy de 8.011 eventos, el
  texto visible `No certificable · Cobertura incompleta` y cero errores de consola;
  las etiquetas Observada y Bootstrap aprobado están cubiertas por pruebas con
  snapshots controlados, sin modificar la BD local.
- Despliegue: revisar/aprobar todas las migraciones pendientes; ejecutar una vez
  `salesforce:sync-monthly-commercial --days=2 --bootstrap-performance-history`,
  conciliar observados/creados/omitidos/conflictos y dejar después el scheduler
  sin opción. Ejecutar rangos mensuales contiguos desde abril con `--to` exclusivo, medir filas,
  duración y llamadas, y validar por mes transiciones/calidad/cobertura antes de
  continuar. La recuperación por ID evita ampliar fechas a ciegas.
- Riesgos: la retención de OpportunityHistory y primeras filas sin etapa previa
  pueden impedir certificar meses; un usuario solo puede bootstrapearse después
  de existir una primera observación normalizada y no contradictoria.

## Aislamiento CI de salesforce:sync-opportunities (2026-08-27)

- Causa: `CampaignCommandsTest` mockeaba usuarios y Opportunities, pero no el
  nuevo `SalesforceOpportunityHistorySyncService`; el container resolvía el
  servicio real y el comando terminaba en FAILURE al intentar Salesforce.
- Corrección exclusivamente de test: History espera una llamada con
  `2026-01-01` / `2026-02-01`, devuelve el contrato neutro completo y se valida
  su salida. Opportunity espera además el tercer argumento `modified=false`.
- Seguridad/producción: sin credenciales, conexiones externas, migraciones,
  backfills ni cambios en comandos, servicios, workflows o lógica funcional.
- Validación: caso focal 1/1 (9 aserciones), `CampaignCommandsTest` 18/18 (81),
  Rendimiento 29/29 (246), HistorySync 4/4 (32), OpportunitySync 4/4 (48) y
  suite CI completa 687/687 (5.154). Pint se limitó al test modificado.

## Comisión de Dirección Comercial en Area Manager (2026-08-26)

- Tarea: se restaura la comisión de Oscar Ortega como el 40 % de la suma de los
  `final_total` de todos los Area Managers globales, redondeada a dos decimales.
- Identidad: Salesforce User ID funcional confirmado `0057R00000B2SGg`; el nombre
  `Oscar Ortega` es solo presentación. El usuario no estaba presente en la réplica
  local durante la comprobación de solo lectura, por lo que no se inventó otra
  identidad ni se bloqueó el cálculo.
- Diseño: `AreaManagerCommissionDashboardService` publica `commercial_director`
  solo en el dataset global. Front, XLSX y snapshot definitivo consumen ese dato;
  los builds por zona lo dejan en nulo y nunca calculan un Oscar parcial.
- Base de datos/configuración: sin migraciones, esquema, variables de entorno,
  rutas, permisos ni configuración administrativa nueva. No se modifican
  snapshots definitivos existentes.
- Seguridad/rendimiento: identidad por ID estable, sin exposición en vistas por
  zona, consultas adicionales, llamadas externas ni segundo build.
- Pruebas: cálculo 1.000 + 2.000 + 3.000 + 4.000 = 10.000 EUR y comisión de 4.000
  EUR; identidad, front global, restricción por zona, XLSX, snapshot y reapertura.
  Focalizadas: `area_manager` 19/19 (111 aserciones),
  `CommercialCommissionDashboardTest` 50/50 (398 aserciones) y
  `CommissionMonthClosureTest` 9/9 (72 aserciones). Suite completa: 687/687
  (5.152 aserciones). Pint y compilación Blade correctos.
- Acciones manuales: ninguna. No requiere migración, backfill ni sincronización.

## Rendimiento comercial en Reservas / Ventas (2026-08-25)

- Correctivo MySQL/UI 26/08: la migración evita los nombres automáticos de 72 y
  69 caracteres mediante `commercial_perf_target_updated_user_fk` y
  `sf_opp_stage_history_uq`. En `informes_local` estaba Pending y solo existía
  `commercial_performance_monthly_targets` parcial, sin índices/FK y con una fila
  local; se eliminó exclusivamente esa tabla y `php artisan migrate` terminó en
  batch 3. `SHOW CREATE TABLE` acredita FK `SET NULL`, UNIQUE e índices finales.
- Filtros: se retiró el bloque interno de Rendimiento. `zone`,
  `commercialDelegation` y `commercial` son controles físicos compartidos; el
  modo activo muestra controles legacy o mes/objetivo y despacha una sola carga.
  Cambios de universo limpian opciones inválidas y realizan una única recarga
  correctiva. Limpiar Rendimiento no altera el objetivo mensual.

- Revisión de integridad 26/08: el mes actual usa el último cutoff diario
  capturado al comenzar el sync, no `now()`; consultas e intervalos se recortan
  a ese instante y el dashboard nunca lo hace avanzar. El payload y la UI
  muestran corte fuente y continuidad certificada. Meses cerrados siguen
  exigiendo el mes completo y cualquier hueco anterior al cutoff deja N/A.
- Dependencias históricas: `reservation_date` NULL se preserva. Una candidata
  cuya Opportunity no está local queda persistida como `opportunity_not_local`;
  su intervalo guarda `unresolved_dependencies`, queda
  `is_kpi_certified=false` y nunca puede producir cancelaciones cero. El
  backfill debe abarcar también la cohorte de reservas anterior y repetir los
  intervalos pendientes tras resolver dependencias.
- Una candidata Cerrada Perdida sin etapa previa se persiste como
  `previous_stage_not_demonstrated` y bloquea el KPI. Si la etapa previa ya era
  Cerrada Perdida se clasifica como permanencia, no como transición ni bloqueo.
  La deuda actual sale de transiciones aún pendientes: intervalos antiguos no
  mantienen deuda después de una reejecución certificada que resuelve la calidad.
- Elegibilidad: el sync de usuarios refresca por ID solo usuarios locales
  relevantes/snapshots abiertos que hayan salido del filtro comercial. Mantiene
  `IsActive` real y cierra el snapshot por inactividad o pérdida de perfil, sin
  cambiar reglas de Leads, Comisiones, Llamadas ni Area Manager.
- El roster histórico carga la identidad desde `salesforce_users` cuando un
  snapshot solapa el período, aunque el perfil actual sea Marketing u otro no
  comercial. El usuario conserva actividad certificada pasada y no reaparece
  como miembro cero después del cierre del snapshot.
- Cumplimiento agregado: resumen y evolución suman los objetivos individuales
  de las filas comerciales incluidas por filtros. `Incidencia de datos` conserva
  eventos operativos cuando corresponde, pero objetivo, cumplimiento, semáforo
  y ranking son NULL. Owners API/Administrador/Marketing/Area Manager sin
  pertenencia demostrable usan esa incidencia y quedan auditados como
  `non_commercial_responsible` en todos los tipos de hito.
- Benchmark Stock comparado en condiciones equivalentes contra el worktree
  detached `5359646`: caso exacto aislado 6,311 s en working tree y 6,348 s en
  baseline, ambos por debajo de 20 s. Los fallos anteriores de 25–40 s se
  atribuyen a variabilidad ambiental, sin cambios en Stock ni en su umbral.
- Encoding real: no aparecen los tokens sospechosos indicados por revisión ni
  mojibake añadido en el diff de
  `app/`, `resources/`, `routes/`, `docs/` o `tests/`. Secuencias antiguas en
  documentación/tests ajenos no se modificaron. Los próximos patches deben
  exportarse preservando UTF-8.
- Auditoría y UI: todas las ventanas usan `>= start` y `< end`; el límite exacto
  del mes siguiente queda excluido. La UI explicita disponibilidad y cutoff de
  cancelaciones, y el asset versionado/manifest corresponde al JS actual.

- Tarea: pestaña funcional mensual solo para Administrador/Dirección, con
  Leads, Opportunities, reservas totales/activas, ventas, cancelaciones,
  objetivos, semáforo, medias de delegación, margen, ranking y cuatro meses de
  evolución. Las pestañas legacy conservan su cohorte y UI.
- Investigación Salesforce read-only: `OpportunityFieldHistory` devolvió cero;
  `OpportunityHistory` devolvió 500 filas recientes y diez cancelaciones
  reservadas en la muestra. Cinco secuencias completas acreditaron transiciones
  reales, incluida una reapertura con dos cancelaciones. La delegación de
  Opportunity es fórmula de `Owner.USR_SEL_Delegacion__c`; `UserFieldHistory` no
  está disponible y no hubo tracking útil de delegación.
- Integridad sénior: una fila por Salesforce User ID/mes. Huecos o cambios de
  delegación no duplican objetivo; conservan actividad individual y deshabilitan
  media/ranking. El roster certificado incluye ceros y participa en equipo.
- Cancelación solo desde transición persistida, con
  `reservation_date <= transitioned_at`; cronologías inválidas quedan auditadas.
  Cobertura continua se acredita en intervalos de sync: uncovered/partial es N/A.
- Persistencia: migración aditiva `2026_08_25_120000` crea objetivos congelados,
  snapshots con único abierto, transiciones de Stage e intervalos de cobertura; añade
  `salesforce_last_modified_at` indexado a Opportunities.
- Seguridad: dataset, auditoría GET y objetivo PUT autorizan servidor a
  Admin/Director; PUT valida CSRF, mes e integer >0. La auditoría paginada
  excluye PII de cliente y no se consulta Salesforce en HTTP.
- Rendimiento: ingesta incremental diaria por `LastModifiedDate`, agregación en
  batch de cuatro meses, sin query por comercial/mes, medias calculadas una vez
  por delegación y caché versionada por todas las fuentes/configuración.
- Scheduler: 07:10 Europe/Madrid, fuera de Campañas/Stock/SEO, comando
  `salesforce:sync-opportunities --days=2 --modified`.
- Verificación final del correctivo MySQL/UI 26/08: específicas de Rendimiento y
  filtros legacy 48/434 y transversales 59/428, correctas. La suite ejecutó 685
  pruebas y 5.123 aserciones: 684 pasaron y falló solo el benchmark Stock por
  latencia ambiental (63,02 s global; 29,51 s y 21,34 s aislado), sin modificar
  Stock ni su umbral; el comparativo controlado previo sigue siendo 6,311 s
  actual y 6,348 s base. Pint se limitó a la migración y al test PHP modificados.
  `composer audit --locked --no-dev` quedó limpio y el build Vite temporal pasó;
  solo se publicaron CSS/JS de Reservas/Ventas y sus entradas del manifest. Se
  ejecutó únicamente la migración local autorizada; no hubo Salesforce ni
  backfills.
- Despliegue: revisar `migrate:status`, enumerar/aprobar todas las pendientes y
  no ejecutar `migrate --force` antes. Backfill corto por `--from/--to`, con
  inicio suficientemente anterior para incluir las reservas de cancelaciones
  posteriores; medir filas/duración/llamadas, revisar dependencias no resueltas,
  repetir intervalos afectados y ampliar por lotes. No ejecutar `--fresh`.
- Riesgo: solo pueden certificarse delegaciones desde el primer snapshot y
  cancelaciones dentro de la retención disponible de OpportunityHistory.

## Presentacion financiera de Irene y Nuria (2026-08-24)

- Tarea: ajuste exclusivamente visual del cuadro `Irene y Nuria` en la pestaña
  Financieros. `Comision final` pasa a la segunda columna y se recuperan como
  contexto importe total, importe financiado, porcentaje financiado,
  rentabilidad, garantia y porcentaje de garantias.
- Decision: se reutilizan directamente las metricas existentes de `summary_rows`;
  no se modifican servicios, formulas, conciliacion, sincronizacion ni mappings.
  Los bloques 1, 2 y 3 siguen sin mostrarse ni aplicarse a Irene/Nuria, cuya regla
  exclusiva permanece en comision neta por 0,50%.
- Archivos: Blade financiero, prueba feature de Comisiones y este handoff.
- Base de datos/configuracion: sin migraciones, variables de entorno ni cambios de
  configuracion.
- Seguridad/rendimiento: sin cambios de autorizacion, consultas o volumen del
  payload; solo se presentan campos agregados ya calculados.
- Pruebas: la prueba focalizada valida el contrato y orden de las 13 columnas, los
  valores informativos formateados y que las tres comisiones estandar permanecen
  en cero. Las verificaciones completas de esta tarea se detallan en la entrega.
- Acciones manuales: ninguna. No requiere sincronizacion Salesforce.

## Auditoria y correccion de Financieros (2026-08-24)

- Tarea: se concilio julio de 2026 por `Opportunity.Id` contra Salesforce y se
  corrigieron la frescura de la replica, la resolucion del responsable financiero,
  la regla exclusiva de Irene/Nuria y la presentacion auditable por delegacion.
- Causa raiz: Salesforce rechazaba `Account.AC_C_EMA_email__c`, un campo opcional;
  el error sanitizado impedia activar el fallback y dejaba la replica incompleta.
  Tras reintentar una vez sin ese campo, el sync acotado guardo julio completo.
  Ademas, Irene/Nuria estaban asociadas al OwnerId comercial en lugar de a su
  zona financiera.
- Conciliacion: la SOQL de contraste Salesforce y la replica local contienen los
  mismos 680 IDs, con 0 ausentes, 0 extras y 0 diferencias en
  comision/descuento. Totales: 12.085.921,06 EUR de importe total,
  5.064.691,00 EUR financiado, 718.638,40 EUR de comision financiera,
  27.086,00 EUR de descuento y 243.080,00 EUR de garantia. Los 29 Sin Zona/General
  aportan cero a comision y descuento y quedan fuera de responsables.
- Resultado: Carlos 4.791,84 EUR; Cristina 1.350,77 EUR; Irene Simon 352,68 EUR
  sobre Alicante/Paterna; Nuria Moracho 149,03 EUR sobre Castellon/Sedavi. La
  suma final de responsables y delegaciones coincide en 6.644,32 EUR.
- Revision previa al commit: una zona financiera explicita desconocida queda
  excluida y visible en diagnostico con sus IDs. Si contiene comision o descuento,
  marca `ready=false` y bloquea el XLSX con HTTP 409. General/Sin Zona mantienen
  su exclusion permitida. Los ajustes de centimos se muestran por delegacion y
  se cuantifican en diagnostico.
- Delegacion de detalle: prioridad actual
  `Owner.USR_SEL_Delegacion__c`; `Delegacion_del_propietario__c` solo es fallback.
  No se certifica igualdad con la agrupacion del report porque no se obtuvo su
  metadata ni exportacion interna de IDs.
- Archivos: servicios financiero/configuracion/sync, controlador, Blade financiero,
  config de comisiones, pruebas de dashboard/sync y documentacion financiera,
  general, contexto, decisiones y este handoff.
- Base de datos: sin migraciones ni datos manuales. Config cambia de excepcion por
  Salesforce User ID a reglas por clave estable de responsable. No hay variables
  de entorno nuevas.
- Seguridad: Salesforce se uso solo en lectura; no se registraron payloads,
  credenciales ni IDs en documentos. Diagnosticos siguen limitados a Admin/IT y
  las autorizaciones de Comisiones no se ampliaron.
- Rendimiento: una consulta local mensual, sin N+1 ni Salesforce en render; los
  tres niveles del payload se derivan de la misma coleccion.
- Pruebas finales: focalizadas de Comisiones/sync/permisos 78/537 y suite completa
  640/4.710, correctas. Un primer pase completo tuvo un timeout del benchmark de
  Stock; paso aislado (2/13) y en la repeticion completa. Pint, Blade y
  `git diff --check` finales tambien son correctos.
- Acciones de despliegue: `php artisan optimize:clear` y sync acotado con
  `php artisan salesforce:sync-opportunities --from=2026-07-01 --to=2026-08-01 -vvv`.
- Riesgo: no se preservo el dataset incompleto anterior de produccion, por lo que
  el delta aproximado de 105.128,40 EUR no puede atribuirse honestamente a una
  lista historica exacta de IDs. La metadata/lista interna del report tampoco
  estuvo accesible; la SOQL produjo 680 IDs y los mismos sumatorios del report,
  pero no se afirma que sus IDs internos fueran exportados.

## Documentación de comisiones financieras (2026-08-24)

- Tarea: se auditó el flujo completo de la pestaña Financieros desde la SOQL de
  `Opportunity`, su materialización en `salesforce_opportunities`, la configuración
  mensual, el servicio de cálculo, el controlador, el front, la exportación XLSX y
  las pruebas. Se creó una guía de presentación/revisión con universo, diccionario
  Salesforce-local, query de contraste, zonas, tramos, fórmulas, ejemplo,
  explicación de cada dato visible y checklist de aprobación.
- Archivos modificados por esta tarea:
  `docs/informe-comisiones-financieras.md`, `docs/informe-comisiones.md`,
  `docs/Calculo_comisiones_comerciales.txt`,
  `docs/Documentacion_general_informes_y_contraste_salesforce.md` y este handoff.
- Decisiones de aquella revisión documental: el bloque 2
  usa comisión financiera válida menos descuento financiero; los intereses vacíos
  o excluidos solo retiran la operación del bloque 2. La resolución anterior por
  Salesforce User ID fue reemplazada por la auditoría descrita arriba. También se
  corrigió la descripción de cierres: Financieros sigue operativo/provisional y no
  dispone de snapshot definitivo propio.
- Base de datos/configuración: sin migraciones, cambios de esquema, `.env`, datos
  ni configuración. Los tramos documentados son defaults; una revisión debe usar
  siempre `commercial_commission_month_settings` del mes seleccionado.
- Seguridad: no se añadieron credenciales, IDs personales ni datos de producción.
  Se documentó que el detalle contiene Opportunity ID/nombre y que el rol Financiero
  ve actualmente todas las zonas; debe validarse ese alcance con mínimo privilegio.
- Rendimiento: sin cambios de ejecución. Quedan documentados el cálculo mensual en
  memoria, el detalle sin paginación y la necesidad de vigilar el plan de la consulta
  por fecha si aumenta el volumen.
- Pruebas: `php artisan test --filter=financieros` correcto (7/41);
  `php artisan test tests/Feature/CommercialCommissionDashboardTest.php` correcto
  (45/294); `php artisan test tests/Feature/CommercialCommissionFormulaSettingsTest.php`
  correcto (10/51); `git diff --check` final correcto.
- Acciones manuales: ninguna para desplegar documentación. Para presentar un mes
  real, sincronizar el rango explícito de Opportunities, confirmar la configuración
  efectiva y conservar fecha/hora del corte y evidencias de conciliación.
- Riesgos/pendientes de aquella revisión: Sin Zona/General no aparecía en el detalle; el detalle no
  expone bases unitarias completas de bloques 1/3; una resincronización puede cambiar
  meses históricos. No se modificaron `PROJECT_CONTEXT.md` ni `DECISIONS.md` porque
  no cambió arquitectura, módulos, convenciones ni decisiones funcionales.

## SEO/Analytics Lote 7 - Correo ejecutivo diario (2026-08-21)

- Se separó el resultado del transporte de la confirmación local. Solo una
  excepción de SMTP permite `sending -> failed`; después de que el sender
  retorna, la transición `sending -> sent` comprueba filas afectadas y, si no
  puede demostrarse `sent`, conserva/restaura `sending`, incrementa
  `confirmation_pending_count` y hace fallar el comando. Así un posible correo
  aceptado no entra de nuevo en el circuito automático de retry.
- Se añadieron settings de 1–10 destinatarios en BD, gestionables únicamente
  por Administrador/Director desde la configuración SEO. La lista se normaliza
  por trim/lowercase y deduplicación; no usa DNS ni contiene secretos SMTP.
- El dataset ejecutivo consume las seis comparativas/evaluaciones actuales, la
  frescura compartida de Search Console/Salesforce/GA4 y un resumen factual de
  Salud técnica. Siempre representa seis filas, respeta fingerprints stale y no
  llama APIs, builders, evaluadores, IA ni SISTRIX.
- Un report diario congelado conserva payload y hash SHA-256. El ledger por
  fecha+recipient hash hace claim atómico antes del envío individual, evita
  duplicados y reintenta solo `failed`; `sending` exige revisión manual.
- `SeoExecutiveDailyReportMail` ofrece HTML y texto plano sin tracking, imágenes
  remotas, JavaScript ni contenido sin escapar. El transporte es síncrono y
  exige mailer distinto de `log`/`array` y remitente distinto del fallback.
- `seo:send-executive-daily-email` registra `ReportSyncRun` sin emails/secretos
  y se agenda a las 08:00 Europe/Madrid con `$monitor` y lock de 30 minutos. No
  crea alertas de negocio por métricas SEO.
- Migración aditiva `2026_08_21_120000`: settings, reports diarios y deliveries.
  No se ha migrado producción ni enviado correo real.
- Validación final: `SeoExecutive` 17 pruebas/183 aserciones,
  `AnalyticalEvaluation` 30/267, `SeoAnalytical` 21/295, SEO 123/1.179,
  patrones 5/46, Design System 5/54 y navegación 6/84, correctos. Suite
  completa: 635/4.682. Pint, Composer audit
  runtime (cero advisories), Vite y `git diff --check` correctos; el build no
  modificó assets versionados.

## SEO/Analytics Lote 6 - Evaluación analítica versionada (2026-08-21)

- Se mantiene `analytical_metric_snapshots` como capa factual separada. Es una
  proyección rolling mutable por identidad/fecha, no append-only. El
  core `AnalyticalEvaluationEngine`, sin DB/SEO/HTTP, aplica una regla resuelta y
  devuelve estado, dirección, banda y reason code cerrados.
- La migración aditiva `2026_08_21_090000` crea rule sets, seis reglas por
  versión y evaluaciones por snapshot/versión. Inicializa `seo_rules_v1` con
  10/20/35 % y materialidad para volúmenes, más 0,5/1/2 pp para CTR y
  0,5/1/2 posiciones. No modifica tablas del Lote 5.
- Baseline bajo no escala por encima de Observación; baseline cero no inventa
  infinito. Una mejora fuerte conserva su banda, pero aparece como Observación
  favorable «Oportunidad / posible anomalía». D-364 no interviene.
- `/informes/seo-analytics/configuracion` es server-side y solo permite
  Administrador/Director. Solo acepta los valores numéricos whitelisteados y un
  motivo; cada save crea vN, audita actor/fecha, bloquea ediciones obsoletas y
  reevalúa únicamente los seis snapshots actuales.
- Un conflicto concurrente redirige sin `withInput`: se descartan thresholds
  stale y la pantalla carga íntegramente la versión activa. Tras una validación
  ordinaria fallida, los hidden conservan con `old()` la identidad y versión de
  origen junto con los campos editables; un retry stale vuelve así a entrar en
  el control transaccional y no puede adoptar silenciosamente la versión nueva.
  Cada evaluación captura current/baseline/cambios/evaluabilidad/motivo y un
  SHA-256 canónico. El dashboard compara el hash con los hechos actuales,
  muestra pendiente si no coincide y las señales históricas usan exclusivamente
  valores capturados.
- El dashboard conserva la tabla factual, añade Estado/Lectura y hasta 50
  señales no `ok` de los últimos 30 días, siempre aisladas por la property
  configurada y escogiendo una sola evaluación reciente por snapshot.
- `seo:evaluate-analytical-snapshots` registra `ReportSyncRun`, no usa red y se
  agenda a las 06:30 Madrid con lock 120. Backfill 1–90 solo mientras v1 siga
  activa; versiones posteriores no reinterpretan automáticamente el histórico.
- No se generan `OperationalAlert` por señales de negocio, email, SISTRIX,
  scoring o recomendaciones. El monitor del scheduler conserva únicamente su
  alerta técnica habitual.
- Producción: migrar, comprobar `seo_rules_v1`/seis reglas, ejecutar
  opcionalmente `--days=30` antes de crear v2 y verificar scheduler/dashboard.
  No se desplegó ni se ejecutó ningún backfill real.
- Validación final: retry `ValidationException`/conflicto 1 prueba/21 aserciones,
  `AnalyticalEvaluation` 30/267, `SeoAnalytical` 21/295, SEO 106/996,
  patrones 5/46, Design System 5/54, navegación estratégica 6/84 y suite
  completa 618/4.499, correctos. Pint `--dirty --test`, Composer audit runtime
  (cero advisories) y `git diff --check` también correctos. El build Vite previo
  del mismo Lote 6 fue correcto y no modificó assets; este cierre no toca
  fuentes frontend compilables.

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

## Regla anterior de Financieros, sustituida (2026-08-11)

- Esta sección conserva la decisión histórica para explicar el cambio. La
  auditoría de 2026-08-24 demostró que `owner_id` pertenece al comercial y
  sustituyó la selección por las claves `zona_irene` y `zona_nuria`.
- El resultado especial continúa sustituyendo los tres bloques normales y no
  usa nombre ni email.
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

## Reducción de amplificación de escrituras del sync mensual (2026-08-25)

- Se auditó `salesforce:sync-monthly-commercial --days=2`. La causa era el
  `upsert` incondicional de Leads y Activities, el `synced_at` nuevo en cada
  Lead, el `updated_at = now()` de summaries y la persistencia individual de
  Users con `raw_payload`. Una repetición idéntica generaba `UPDATE` aunque el
  estado funcional no cambiara.
- Se añadió `ChangedRowUpsert`, que por chunk carga en una consulta las filas
  locales, normaliza casts, fechas UTC, booleanos y JSON (incluyendo orden de
  claves y equivalencia `null`/cadena vacía), y separa `inserted`, `updated` y
  `unchanged` antes de escribir. La comparación usa todos los atributos de la
  proyección recibida; no depende únicamente de `LastModifiedDate`.
- Leads excluye `synced_at` de la decisión de cambio, pero lo actualiza junto
  con `updated_at` cuando cambia un atributo persistible. La reconciliación de
  ausentes ya no usa `synced_at` como marca de presencia: compara por chunks
  contra los Salesforce IDs activos observados en el run. Deleted/merged
  repetidos se clasifican sin volver a escribir.
- Tasks, Events y Users usan la misma clasificación por chunks. Summaries se
  siguen recalculando para todo el período, conservando las reglas matemáticas,
  pero solo se persisten si cambia el resultado; el recálculo incremental queda
  como optimización posterior para no introducir riesgo funcional en este lote.
- `saved` conserva compatibilidad y en Leads significa registros activos válidos
  procesados, no escrituras físicas. `active_inserted`/`active_updated`/
  `active_unchanged` desglosan ese universo; `persisted_inserted`/
  `persisted_updated`/`persisted_unchanged` incluyen también deleted/merged. Las
  claves compatibles `inserted`/`updated`/`unchanged` son aliases del total
  `persisted_*`. Leads añade `deleted_merged_changed`/
  `deleted_merged_unchanged`, y summaries añade `summaries_changed`/
  `summaries_unchanged`. `ReportSyncRun` conserva `summaries` como entero y
  publica el desglose adicional sin payloads ni datos personales.
- La metadata separa el último run completado del último run que cubre todo el
  período del dashboard. `salesforce_leads_synced_at`, `activities_synced_at`,
  `sync_run_id` y `sync_run_status` proceden del último `ReportSyncRun`
  completado aunque el scheduler solo haya sincronizado dos días;
  `dataset_cutoff_at` conserva el run con cobertura completa o el mínimo cutoff
  local como fallback. Los `synced_at` por fila quedan como fecha del último
  cambio real.
  El uso de `MAX(salesforce_leads.synced_at)` en alertas de atribución de
  Campañas se mantiene como señal de cambio de datos, evitando reconstrucciones
  tras consultas idénticas. La invalidación de caché por run se conserva para
  publicar el nuevo cutoff.
- Archivos modificados: comando mensual; servicios de Leads, Activities, Users
  y summaries; nuevo comparador `ChangedRowUpsert`; metadata del dashboard de
  Leads; pruebas unitarias de los cuatro syncs/summaries y prueba funcional del
  comando.
- Base de datos/configuración: sin migraciones, backfills, cambios de `.env` ni
  cambios MySQL. No se modificaron `binlog_format`, `binlog_row_image` ni
  compresión. No hay acciones manuales previas al despliegue.
- Seguridad: no se registran payloads, IDs, emails ni datos personales en las
  nuevas estadísticas. Se conserva el alcance SOQL y la autorización existente.
- Rendimiento: una lectura local por chunk (200 Leads; 500 Activities/Users/
  summaries) sustituye escrituras redundantes. El conjunto de IDs observados es
  proporcional a la ventana consultada; con la ventana operativa de dos días es
  acotado. `withoutOverlapping(30)` permanece activo.
- Pruebas: regresión específica final 21 tests/179 aserciones, incluyendo
  dashboard de 30 días con run incremental de dos días sin cambios locales;
  grupos Monthly Commercial, Leads, Campañas, Comisiones y auditorías
  190/1.370; suite completa 646/4.818. Pint correcto.
  `composer audit --locked --no-dev`: sin advisories.
  `git diff --check`: correcto. No hubo requests reales a Salesforce ni otros
  servicios externos.
- Medición recomendada tras despliegue autorizado: capturar posición de binlog
  y contadores Performance Schema inmediatamente antes y después de dos runs
  consecutivos con la misma ventana; usar el segundo run como AFTER estable.
  Correlacionar del `ReportSyncRun` los contadores `inserted`/`updated` con los
  deltas de `salesforce_leads`, `salesforce_activities`, `salesforce_users` y
  summaries. Persistirán las escrituras pequeñas del propio run/caché y cualquier
  cambio real ocurrido en Salesforce.
- Riesgo residual: el cálculo completo de summaries conserva su coste de CPU y
  lectura aunque ya no amplifique escrituras. La clasificación es previa al
  `upsert`; el lock programado evita solapamiento normal, pero ejecuciones
  manuales concurrentes siguen dependiendo de los índices únicos existentes.
# Evolución de cierres y responsables de comisiones (2026-08-28)

- Se unificó desde 2026-07 la meta editable de entregas: Delegaciones consume `area_manager.assignments.*.objectives.deliveries`; junio y meses anteriores conservan `delegations.goals`. Los contadores reales de ambos informes no se modificaron ni reconciliaron entre sí.
- Los cierres económicos admiten seis ámbitos independientes: comerciales, delegaciones, Area Manager, financieros, Call Center y Contact Center. Administrador prepara; Administrador o Dirección aprueban/reabren; Auditor no escribe.
- Call Center y Contact Center detallados quedan restringidos a Administrador tanto por pestaña como por resolución server-side y export detallado. Dirección recibe una superficie mínima de aprobación.
- El Auditor recibe una proyección final-only por entidad, estado, aprobador, fecha y alertas de jefe de tienda. No puede gestionar penalizaciones ni exportar datasets detallados.
- Salesforce Describe confirmó `Delegacion__c.DEL_BUS_Jefe_Tienda__c` como lookup a `User`. `Delegacion__History` existe, pero las consultas de solo lectura no devolvieron eventos de ese campo ni desde 2026-07-01 ni históricamente; por ello julio no puede afirmarse como reconstruido y se representa como histórico no verificable.
- Se añadió persistencia temporal local y el comando `salesforce:sync-delegation-managers --from=2026-07-01`. Requiere ejecutar migración y después el comando en cada entorno; el scheduler lo refresca diariamente a las 04:15. No se ejecutó sincronización ni migración en esta tarea.
- La comisión no se prorratea: el 100 % corresponde al último responsable verificable del periodo; varias identidades generan alerta no bloqueante que queda dentro de la fila canónica y, por tanto, del snapshot de Delegaciones.
- La verificabilidad exige cobertura temporal continua del mes. Los eventos de Field History generan intervalos; las observaciones diarias solo cubren cada día observado. Un evento antiguo o una lectura actual aislada no validan julio retroactivamente.
- Backfill controlado de julio: IT/Dirección debe confirmar cada delegación y ejecutar `commissions:record-delegation-manager-evidence` indicando Salesforce Delegation ID, Salesforce User ID, mes, fuente, referencia documental y quién registra. Las delegaciones no confirmadas permanecen como histórico no verificable. No se ejecutó ningún backfill.
- Los scopes `financials`, `call_center` y `contact_center` solo pueden cerrarse desde 2026-07; junio y meses anteriores mantienen los tres scopes históricos.
- El Auditor usa snapshot para scopes definitivos y dashboard canónico vivo con `includeDetails=false` para scopes abiertos, proyectando inmediatamente solo entidad y comisión final.
- Si Call Center se visualiza con fechas contractuales personalizadas, el panel de cierre informa el rango mensual y total canónico que se congelará; el snapshot nunca consume esos query parameters.
- Seguridad: no se sincronizan permisos `ReportUser`, no se consulta Salesforce durante el render y las identidades económicas usan Salesforce User ID.
- Corrección sénior final: los tres scopes nuevos se rechazan en backend antes de 2026-07; el Auditor usa datos vivos final-only en provisional/pending/reopened y snapshot en definitivo; tanto la vista como el endpoint JSON reducen el estado a situación, aprobador y fecha. Call Center informa el total mensual canónico cuando existen filtros contractuales personalizados y sus snapshots nunca consumen esos filtros.
- Cobertura histórica: un jefe solo es verificable si intervalos con Salesforce User ID cubren el mes completo sin huecos. Se cubren cero cambios con evidencia mensual, seguimiento diario completo, rotaciones de dos o tres responsables, huecos posteriores y observaciones puntuales insuficientes. Una cobertura sin responsable no se considera verificable.
- Backfill de julio: no se ejecutó. Tras confirmación real de IT/Dirección se registra por delegación con `commissions:record-delegation-manager-evidence`, Salesforce Delegation ID y User ID, mes, fuente permitida, referencia documental y registrador. La ausencia de confirmación conserva el warning explícito.
- Validación ejecutada: `CommissionGovernanceEvolutionTest` 9/28; `CommercialCommissionAuditProjectionServiceTest` 2/17; `CommissionVisibilitySecurityTest` 7/47; `CommissionMonthClosureTest` 16/120; `CommercialCommissionDashboardTest` 50/398; `ReportAccessManagementTest` 8/53; configuración/penalizaciones/Call Center/Contact Center 22/135; suite completa 725 pruebas/5.401 aserciones. Lint PHP de 26 archivos, compilación Blade y `git diff --check` correctos. Por restricción expresa no se ejecutó Laravel Pint.

## Correctivos de despliegue y UX de cierres de Comisiones (2026-08-28)

- Causa del `SQLSTATE 42S22`: la primera versión ya ejecutada de `2026_08_28_090000_create_salesforce_delegation_manager_history_table` no contenía metadata de cobertura, mientras el código posterior consultaba `coverage_from`. Se añadió la migración incremental `2026_08_28_100000_add_coverage_metadata_to_salesforce_delegation_manager_history_table`, segura tanto sobre el esquema legacy como sobre instalaciones nuevas.
- La migración añade solo cuando faltan `coverage_from`, `coverage_to`, `evidence_reference` y `recorded_by`, además del índice `sf_deleg_mgr_coverage_idx`. En filas legacy, `coverage_from` se deriva de `effective_at`; `coverage_to` permanece nulo y `history_verified` no se modifica. No se borran ni promocionan observaciones.
- Mientras la migración esté pendiente, `DelegationManagerHistoryResolver` inspecciona el esquema una vez por build, no consulta columnas ausentes y devuelve responsables no verificables con warning administrativo saneado. Delegaciones y la proyección del Auditor continúan cargando sin inventar responsables.
- La causa adicional del 500 al emitir warnings era la firma de `SanitizeLogRecords`: Laravel 13 entrega `Illuminate\Log\Logger`, no directamente `Monolog\Logger`. El tap admite ambos tipos y conserva el mismo procesador de redacción de secretos.
- Contact Center no tenía una regresión de fórmula. La traza llegaba al histórico de Delegaciones durante la construcción de superficies de Comisiones y fallaba antes del render. Tras el guard de esquema y el logger compatible, el tab de Administrador responde correctamente tanto vivo como desde snapshot definitivo.
- El subtab Operaciones usaba el trigger `deliveries` mientras su panel mensual se llama `operations`; la inicialización JavaScript ocultaba todos los paneles. Trigger y panel vuelven a compartir clave. Validación manual: Operaciones visible por defecto, cambio a Tasaciones y vuelta correctos, y cambio entre 59 comerciales sin panel vacío.
- Dirección dispone de una superficie consolidada con los scopes disponibles del mes. Los no preparados no ofrecen aprobación; los pendientes muestran una acción explícita por scope; los definitivos muestran aprobador, fecha, importe congelado y reapertura con motivo. Los primeros cuatro scopes reutilizan el dashboard activo para evitar builds duplicados; Call/Contact se calculan sin detalle y nunca se exponen operativamente.
- Seguridad: no se relajaron permisos. Solo Administrador abre detalle Call/Contact; Auditor conserva final-only y no puede preparar, aprobar, reabrir, exportar detalle ni gestionar penalizaciones. No hubo escrituras ni consultas a Salesforce.
- Rendimiento: el chequeo de esquema es por resolución batch. La superficie de Dirección no recalcula los cuatro dashboards navegables fuera del bloque activo; para revisarlos enlaza a su pestaña antes de aprobar. Los snapshots definitivos se reutilizan.
- Pruebas focalizadas correctas: histórico/migración 13/47, visibilidad y aprobaciones 11 pruebas, cierres 16/120, proyección Auditor 2/17, dashboard 50/404, Contact Center 2/27 y logging 3/11. La suite global ejecutó 734 pruebas/5.455 aserciones: 733 pasaron; el único fallo fue el benchmark ajeno `StockRecommendationCandidatePaginationTest` por 34,62 s frente a su umbral de 20 s, repetido aisladamente en 34,21 s. No se modificó Stock.
- Acciones manuales tras despliegue autorizado: ejecutar `php artisan migrate`, limpiar caché de aplicación/vistas según el procedimiento de despliegue y verificar Delegaciones/Auditor. No se ejecutó la migración sobre la base local o producción en esta tarea. Tampoco se ejecutó backfill de julio.
- Restricciones respetadas: no se ejecutó Laravel Pint ni formatters globales; no hubo commit, push, PR ni deploy.

## Preparación automática, Auditor por scope y evidencia de cierre (2026-08-28)

- Se separaron dos hechos independientes de Jefes de tienda. El responsable al cierre se obtiene de evidencia que cubre el último instante del mes y recibe inicialmente el 100 % de la comisión. La cobertura de rotaciones solo se declara verificada cuando intervalos confiables cubren todo el mes. Un jefe de cierre confirmado puede mostrarse con alerta de rotaciones no verificables; no se prorratea ni se afirma que fuera el único responsable.
- El backfill no copia observaciones actuales hacia julio. `commissions:record-delegation-manager-evidence` admite `--evidence-type=month_end|full_month` y `--dry-run`. Para lotes se añadió `commissions:import-delegation-manager-evidence <csv> --dry-run`; valida IDs Salesforce, catálogo local, fuente, referencia y registrador antes de una transacción única. No se ejecutó ningún backfill.
- Ejemplo de validación sin escritura: `php artisan commissions:import-delegation-manager-evidence storage/app/private/jefes-julio.csv --dry-run`. Cabeceras: `delegation_id,delegation_name,manager_id,manager_name,month,source,reference,recorded_by,evidence_type`. Solo una confirmación documental que cubra todo julio puede usar `full_month`; para confirmar exclusivamente quién estaba al cierre se usa `month_end`.
- La causa del 500 del Auditor era agotamiento de 128 MB al construir seis dashboards vivos en una petición. La proyección ahora obtiene metadata de seis estados pero construye únicamente `audit_scope`. Definitivo consume snapshot; provisional, pendiente o reabierto calcula solo ese scope con `includeDetails=false` y proyecta inmediatamente entidad/comisión final. Un fallo queda aislado como bloque no disponible, se registra sin datos sensibles y no se convierte en cero.
- Preparar ya no acepta checkboxes del navegador. `CommercialCommissionSourceReadinessService` comprueba metadata `report_sync_runs`, cobertura temporal, estado y freshness; un run completado con cero filas es válido. Los comandos de Opportunities, `Resena__c` comerciales y Tasaciones registran sus ejecuciones. Fuentes locales se validan por esquema, no por recuento.
- Las reseñas de Delegaciones se verifican mediante el estado técnico y `fetched_at` de `CommercialCommissionDelegationReviewsService`, que consume el endpoint interno y su caché. No se usa `salesforce_reviews` como indicador. Fallo, respuesta incompleta o caché desactualizada bloquean Preparar sin exponer URL ni credenciales.
- Al preparar se guarda el snapshot candidato y su `source_state`. Aprobar transforma exactamente esa versión en definitiva sin reconstruir el dashboard. Una reapertura conserva snapshots anteriores y una preparación posterior crea una versión nueva. Dirección lee el importe candidato; no recalcula los seis dashboards.
- La UI de Administrador es una tabla compacta de fuentes/estado/acción y deja approve/reopen únicamente bajo `Acciones excepcionales`. Dirección dispone de una tabla compacta con seis scopes, importe preparado, aprobador, fecha y acciones bajo demanda. Un rango contractual personalizado de Call Center no ofrece Preparar hasta volver al mes canónico.
- Archivos nuevos de esta iteración: servicios de readiness y evidencia, importador CSV y prueba de readiness. No hay migraciones adicionales a las ya descritas para el histórico.
- Validación actual: sintaxis correcta en los 33 PHP modificados y compilación Blade correcta. Focalizados: gobierno 16/60; proyección Auditor 3/22; readiness 3/9; visibilidad/seguridad 11/78; cierres 16/120; Call Center 6/45; permisos 8/53; regresión específica de Oscar/snapshot 1/10. Suite completa: 741 pruebas y 5.483 aserciones; 740 pasaron. El único fallo fue el benchmark preexistente `StockRecommendationCandidatePaginationTest`: 43,47 s frente a su umbral de 20 s. Stock no se modificó. `git diff --check` correcto. No se ejecutó Pint, migración, sincronización ni servicio externo.

## Selector mensual del Auditor y Jefes fuera del catálogo comercial (2026-08-31)

- El Auditor puede seleccionar cualquier mes entre julio de 2026 y el mes actual. El selector conserva `audit_scope`, y los enlaces de scope conservan `month`; el backend limita meses anteriores a julio y meses futuros sin construir dashboards adicionales. Las etiquetas se generan con Carbon en español, sin hardcodear nombres de meses.
- Se mantiene la restricción final-only y un único dashboard económico por request: el selector se construye localmente y no consulta Salesforce ni activa los otros cinco scopes.
- La validez de un Jefe de tienda ya no depende de `salesforce_users`. La identidad canónica sigue siendo el Salesforce User ID del lookup `Delegacion__c.DEL_BUS_Jefe_Tienda__c`; el histórico persiste ID y nombre sin foreign key ni creación de `ReportUser`/`SalesforceUser`.
- El import CSV conserva validación estricta, dry-run y transacción atómica. `manager_id` debe ser un Salesforce User ID de 15/18 caracteres con prefijo `005`, pero su ausencia en `salesforce_users` ya no bloquea el backfill. El catálogo local de delegaciones, fuente, referencia, registrador, mes y tipo de evidencia siguen siendo obligatorios.
- La sincronización obtiene el nombre actual mediante `DEL_BUS_Jefe_Tienda__r.Name`. Solo los IDs históricos o relaciones sin nombre se resuelven con una única consulta batch a `User`; no existe consulta por delegación ni se amplía el universo de la sincronización comercial.
- No hay migraciones, cambios de esquema, variables de entorno ni acciones manuales nuevas. No se ejecutó el CSV de julio, ninguna sincronización real ni escritura en Salesforce.
- Pruebas nuevas: import dry-run con manager externo, persistencia sin crear usuarios/permisos, manager ya presente, rollback atómico; sincronización de un Area Manager como Jefe, lookup nulo y resolución histórica batch; selección julio/agosto, locale español, límites temporales, conservación mutua de mes/scope y un único scope vivo.
- Validación focalizada final de esta iteración: `23/152` en import, sync de Jefes, proyección Auditor y seguridad/selector; además cierres `16/120`, permisos `8/53` y sync de usuarios existente `4/19`. Suite completa: 750 pruebas/5.497 aserciones, 743 correctas. Permanecen siete fallos del working tree previo: cuatro cálculos de Delegaciones, una penalización financiera, la meta histórica junio/julio y una auditoría de calidad de Leads; no se modificaron porque esta tarea no autoriza cambios económicos ni de Leads. Compilación Blade y lint PHP correctos. No se ejecutó Laravel Pint.

## Alertas de Jefes y presentación final-only del Auditor (2026-08-31)

- La alerta funcional de Jefes de tienda ya no depende de cobertura histórica completa. Cero, uno o dos Salesforce User ID distintos demostrados no generan aviso; únicamente tres o más generan una advertencia no bloqueante con la cantidad real y el mes localizado en español. Se mantienen internamente los estados de responsable al cierre y cobertura de rotaciones, sin borrar evidencia ni prorratear la comisión.
- Las superficies de Administrador, Dirección y Auditor filtran las alertas mediante `store_manager_distinct_count > 2`, no mediante búsquedas en mensajes humanos. Esto también evita presentar como incidencias las advertencias antiguas congeladas en snapshots legacy que no contienen un contador superior al umbral.
- La proyección reducida del Auditor para Delegaciones incorpora solo `manager_name`, además de delegación y comisión final. No expone Salesforce User ID, referencias, evidencias ni histórico. Los demás scopes mantienen su contrato anterior.
- Los estados visibles del Auditor se proyectan como `Provisional`, `Pendiente de aprobación`, `Definitivo` y `Reabierto`. `Definitivo` reutiliza la variante existente `type-pill group` (verde); pendiente y reabierto usan `type-pill pending`.
- Inspección de solo lectura del snapshot definitivo local de Delegaciones de julio: versión 2, 34 filas; las 34 contienen `store_manager_name` y el ID interno del responsable. Ninguna contiene un contador de responsables utilizable y las 34 conservan el antiguo texto de cobertura incompleta. No se modificó el snapshot: el Auditor puede mostrar directamente el nombre congelado y la proyección ignora los avisos legacy sin contador. Un snapshot aún más antiguo sin `manager_name` muestra `No verificable` sin recalcular ni inventar el responsable.
- No hubo cambios de fórmulas, sincronizaciones, readiness, preparación/aprobación, CSV/backfill, base de datos ni configuración. No se ejecutaron sincronizaciones, backfills, migraciones ni reaperturas.
- Validación focalizada: proyección Auditor, visibilidad, snapshots, cierres y permisos `36/277`; resolver e histórico `14/51`. Suite completa: 754 pruebas/5.535 aserciones, 747 correctas. Persisten exactamente los siete fallos previos ya documentados: cuatro cálculos de Delegaciones, import de penalización financiera, meta histórica junio/julio y calidad de Leads. Lint PHP de los ocho archivos PHP afectados correcto. Compilación Blade y `git diff --check` correctos. No se ejecutó Laravel Pint.

## Cierre final de Comisiones y HTTPS tras proxy (2026-08-31)

- Se localizó la última superficie que mostraba avisos legacy de cobertura: `CommercialCommissionSourceReadinessService` copiaba sin filtrar `dashboard.warnings` hacia la preparación de Administrador. Ahora elimina únicamente alertas asociadas estructuralmente a filas de Jefes con contador `<=2` y conserva/reincorpora las de `store_manager_distinct_count > 2`. Los estados reales del endpoint de reseñas, oportunidades y demás fuentes permanecen intactos y pueden seguir bloqueando preparación.
- La causa del Mixed Content era que Laravel recibía HTTP desde el reverse proxy y, al no existir proxies confiables, ignoraba `X-Forwarded-Proto: https`. `request()->getScheme()` y las URLs derivadas de `fullUrlWithQuery()`/`route()` quedaban en `http`; no era un fallo específico de los tabs.
- `bootstrap/app.php` sustituye el middleware oficial por `TrustConfiguredProxies`, una extensión mínima del `TrustProxies` de Laravel que consume la lista cacheable de `config/app.php`. Se confían `X-Forwarded-For`, host, puerto, protocolo y prefijo mediante las constantes estándar. No se acepta `*`, no se hardcodea dominio, no hay parser propio ni `URL::forceScheme`; local HTTP permanece operativo y un header HTTPS desde origen no confiable se ignora.
- Producción debe configurar `APP_URL=https://informes.app.hrmotor.com`, `TRUSTED_PROXIES` con la IP/CIDR real del proxy y asegurar `X-Forwarded-Proto: https`. Después debe limpiar/recachear configuración. No se modificó `.env` ni el proxy desde esta tarea.
- Los seis fallos económicos previos de Comisiones tenían una causa común no económica: `CarbonImmutable::createFromFormat('Y-m', ...)` heredaba el día actual. Ejecutado un día 31, junio desbordaba a julio y activaba la meta compartida, además de desplazar operaciones, reseñas y una fila de penalización. El formato `!Y-m` reinicia fecha/hora antes de `startOfMonth`; el import usa también formatos parciales reiniciados. No se cambiaron fórmulas, tramos ni universos.
- Fallos corregidos: cuatro métodos de `CommercialCommissionDashboardTest` sobre meta/bonus, agrupación de rentabilidad, alias/meta y comisión de reseñas; `CommercialFinancingPenaltyImportTest::test_importa_por_email_mes_y_sustituye_el_mes_previamente_cargado`; y `CommissionGovernanceEvolutionTest::test_july_delegation_goal_is_derived_from_area_manager_while_june_keeps_legacy_goal`. Los seis focalizados pasan con 61 aserciones.
- El séptimo fallo, `LeadVentaAndQualityAuditTest::test_incidencias_de_calidad_auditan_exactamente_el_kpi_y_no_fusionan_nombres`, permanece aislado. Test y `SalesforceLeadDashboardDatasetService` no difieren de `HEAD`. La implementación vigente excluye de totales (`INCLUDE_UNCLASSIFIED_IN_TOTALS`) el lead sin comercial cuya delegación también queda sin clasificar; por ello KPI y auditoría devuelven cero mientras el fixture espera su ID. No se alteró Leads ni su expected sin una decisión funcional de ese dominio.
- Pruebas nuevas de proxy: una request desde proxy confiable genera HTTPS para los seis tabs y URLs de formularios/exports; un origen no confiable no puede falsificar protocolo; local sin forwarded headers conserva HTTP. Readiness prueba que desaparece el warning legacy, se conserva un warning legítimo de caché y una rotación de tres Jefes sigue siendo no bloqueante.
- Validación: nuevos focalizados de proxy/readiness `9/33`; seis regresiones de Comisiones `6/61`; suite completa final `760` pruebas y `5.589` aserciones, con `759` correctas y únicamente el fallo independiente de Leads descrito. No se ejecutó Laravel Pint, Salesforce, backfill, migración, reapertura, commit, push, PR ni deploy.

## Readiness conservador de reseñas de Delegaciones (2026-09-01)

- `CommercialCommissionSourceReadinessService` deja fuera de la validación del endpoint exclusivamente filas con `reviews_technical_status=not_applicable` que ya demuestran ausencia total de materialidad: objetivo y entregas no positivos, objetivo no alcanzado y rentabilidad, prima previa a reseñas, comisión de reseñas y comisión total exactamente a cero.
- La excepción no cambia `delegation_rows`, estados técnicos, contadores, fechas, fórmulas ni mapping de ubicaciones. Una fila residual permanece visible como `not_applicable`; únicamente deja de exigir una consulta que no puede alterar su resultado económico.
- Cualquier campo requerido ausente/no numérico, cualquier materialidad o cualquier estado distinto de `available`/`not_applicable` conserva el bloqueo. `not_configured`, `transport_error`, `remote_error`, `unavailable` y estados desconocidos siguen siendo errores. Las filas `available` continúan sujetas al TTL de `reviews_fetched_at`.
- Si todas las filas son `not_applicable` e inertes, reseñas queda `ready` sin falsear `fetched_at`; en una mezcla, freshness se calcula solo sobre filas realmente aplicables. No se añadieron consultas SQL/HTTP, migraciones, variables de entorno, frontend ni assets.
- Validación: readiness `22/67`, dashboard de Comisiones `50/404`, suite completa `777/5.654`, Pint focalizado correcto y `git diff --check` correcto. No se consultó Salesforce ni el endpoint real y no hubo commit, push, merge ni deploy.

# Auditoría preparatoria de campos Salesforce (2026-09-01)

## Resumen

- Se auditó el recorrido actual de procedencia, canal, medio, portal,
  delegación y adquisición desde SOQL hasta tablas, resolvers, datasets,
  informes y exports.
- Se creó `docs/auditoria-migracion-campos-salesforce.md` porque la documentación
  existente está separada por informe y no había una matriz transversal capaz
  de registrar las colisiones entre Leads, Campañas, Calls, Opportunities y
  SEO sin duplicar o mezclar sus reglas.
- Se añadieron pruebas de caracterización para `LeadPortalResolver`, el universo
  legacy exacto de `CampaignLeadSyncService`, `dryRun`, fallback SOQL, doble
  upsert y prioridades auxiliares de Calls y Opportunities.
- No se modificó PHP productivo, SOQL productivo, frontend, rutas, configuración,
  esquema, datos ni reglas de negocio.

## Línea base Git

- Rama: `main`.
- Commit: `4a4037a1036f0ce32800e003abdb571289cd10a1`.
- Estado inicial: limpio; `origin/main` coincidía con HEAD.
- El commit de referencia solicitado
  `a6b7ef768d0c261e18508e902b846abfffc12707` no existe en los objetos Git
  locales. No fue posible calcular ahead/behind o merge-base y no se hizo fetch,
  reset ni checkout para alterar la línea base.

## Archivos modificados

- Nuevo `docs/auditoria-migracion-campos-salesforce.md`: matriz, flujos,
  comportamiento real, colisiones, doble escritura, riesgos y bloqueos.
- Modificado `docs/decisiones-negocio-pendientes.md`: decisiones que bloquean la
  migración futura, sin resolverlas.
- Nuevo `tests/Unit/LeadPortalResolverCharacterizationTest.php`: canal,
  prioridades, normalización, vacíos, fallback y source actuales.
- Nuevo `tests/Feature/CampaignLeadSyncCharacterizationTest.php`: filtro y SELECT
  legacy, cinco candidatos, exclusión, dry-run, fallback y doble upsert.
- Modificado `tests/Unit/OpportunityPortalResolutionTest.php`: prioridad del
  portal en el Lead relacionado.
- Modificado `tests/Feature/SalesforceCallSyncServiceTest.php`: consulta auxiliar
  y prioridad del Lead cuando `Task.Portales__c` no clasifica.
- Modificado este handoff. No se actualizó `PROJECT_CONTEXT.md` ni
  `DECISIONS.md`: no cambió arquitectura ni se adoptó una decisión difícil de
  revertir.

## Decisiones adoptadas

- Mantener exactamente las prioridades y universos existentes.
- Tratar `salesforce_leads.fuente_origen` y `.medio_origen` como columnas legacy
  alimentadas por `LEA_SEL_*`; no reutilizarlas para API Names nuevos.
- Documentar `Medio_origen__c` de SEO como integración existente aislada, no
  como precedente para Leads/Campañas.
- Congelar mediante test la doble escritura peligrosa de Campañas sin corregirla
  en esta fase.

## Base de datos y configuración

- Sin migraciones, nuevas columnas, índices, backfill ni cambios de `.env`.
- Sin configuración manual necesaria para esta fase.

## Seguridad

- No se usaron credenciales reales ni se llamó a Salesforce.
- Los clientes Salesforce de tests son mocks/stubs y usan datos sintéticos.
- No se escribieron tokens, secretos, datos personales ni payloads productivos
  en tests o documentación.
- Los endpoints y controles de autorización no cambiaron.

## Rendimiento

- No se añadieron queries de producción ni se cambiaron ventanas, chunks,
  reintentos, upserts, índices o cachés.
- La fase futura debe evaluar crecimiento del SELECT y del OR Salesforce,
  volumen adicional, doble escritura, backfill y reatribución masiva.

## Pruebas y verificaciones

- Línea base relacionada antes de cambios: `php artisan test` sobre 8 archivos
  relacionados: 115 pruebas, 795 aserciones, todo correcto.
- Caracterización inmediata: 25 pruebas, 106 aserciones, todo correcto. De
  ellas, 18 casos son nuevos (incluidos datasets del data provider).
- Suite relacionada final de Leads, Campañas, Calls, Opportunities,
  sincronizadores y SEO: 160 pruebas, 993 aserciones, todo correcto.
- Suite completa `php artisan test`: 778 pruebas, 5.679 aserciones, todo
  correcto.
- `php vendor/bin/pint --test` sobre los cuatro PHP modificados: correcto.
- `composer validate --no-check-publish --strict`: correcto.
- `composer audit --locked --no-dev`: sin vulnerabilidades conocidas.
- `npm run build`: correcto. Vite avisó de `/images/login-bg.jpg` resuelta en
  runtime y de una deprecación Node; no son fallos de este cambio. Los hashes
  generados por el build se restauraron porque no hubo cambios frontend.
- `git diff --check`: correcto.
- Fallos preexistentes observados: ninguno en las suites ejecutadas.
- Fallos introducidos: cero.

## Acciones manuales necesarias

- Ninguna para desplegar esta fase documental/de pruebas.
- Antes de implementar la migración, Dirección debe resolver las diez decisiones
  registradas en la auditoría y debe verificarse la metadata/FLS real de los API
  Names candidatos en un proceso separado y de solo lectura.

## Riesgos y pendientes

- Crítico: colisión semántica de columnas locales legacy y API Names nuevos.
- Crítico: ampliar el filtro Campañas modificaría universo y volumen.
- Crítico: el upsert parcial de Campañas puede sobrescribir columnas generales
  con `null`; además no hay transacción entre las dos tablas.
- Alto: prioridades diferentes entre dashboard, canalización legacy, Calls y
  Opportunities.
- Alto: Exposición tiene fallbacks exclusivos de owner/comercial.
- Pendientes funcionales completos en
  `docs/auditoria-migracion-campos-salesforce.md` y
  `docs/decisiones-negocio-pendientes.md`.

## Confirmación de no regresión

- Universo: NO modificado.
- Conteos: NO modificados.
- Reglas de negocio: NO modificadas.
- Prioridades: NO modificadas.
- Campos Salesforce nuevos: NO integrados.
- SOQL productivo: NO modificado.
- Backfill: NO realizado.
- Producción: NO modificada.

# Integración segura de campos Salesforce (Tarea 2, 2026-09-01)

## Resumen

- Metadata real de Lead validada con `SalesforceClient::describe('Lead')` y un
  SELECT de solo lectura: 14/14 campos existen y son consultables. No se
  imprimieron datos de Lead, URLs, tokens ni credenciales.
- Migración aditiva para conservar raw nuevo y legacy simultáneamente en
  `salesforce_leads` y `campaign_salesforce_leads`: clasificación 255,
  UTM 70, adquiridos legacy 255 y traza JSON. Sin índices nuevos.
- Resolver puro central para valor efectivo, campo ganador, fallback, conflicto
  y raw por fuente, canal, medio, delegación y cinco UTM.
- El sync mensual obtiene/persiste los nuevos campos como opcionales. Su query
  reducida omite esas columnas para no borrar valores previos.
- Campañas mantiene exactamente el WHERE legacy. Enriquece únicamente los Leads
  que ya pertenecían al universo y un Lead UTM-only sigue excluido.
- Se corrigió la doble escritura: Campañas conserva columnas generales y
  `raw_payload`; solo completa/actualiza adquisición y UTM no vacíos. Carga
  existentes por chunk, escribe en batch y usa una transacción corta por chunk.
- No se migraron los consumidores funcionales de Leads, Llamadas,
  Reservas/Ventas ni el attribution builder; los conteos visibles no cambian.

## Archivos

- Nuevos: migración `2026_09_01_090000_add_salesforce_lead_origin_and_utm_fields`,
  `SalesforceLeadFieldResolver` y tests del resolver/migración.
- Modificados: modelos `SalesforceLead` y `CampaignSalesforceLead`, sync mensual,
  sync de Campañas, tests de ambos sync, auditoría transversal, decisiones de
  negocio y este handoff.
- Eliminados: ninguno. No se actualizan `PROJECT_CONTEXT.md` ni `DECISIONS.md`:
  no cambia el límite de módulos ni se adopta una arquitectura difícil de
  revertir; se implementan reglas funcionales ya aprobadas.

## Base de datos y configuración

- Requiere ejecutar la nueva migración antes de activar el código.
- No hay variables de entorno, dependencias, índices ni configuración nueva.
- No se ejecutó migración sobre la base local, backfill ni resincronización.

## Seguridad y rendimiento

- Salesforce se usó solo mediante describe/SELECT. No hubo DML, secretos en
  fixtures o datos productivos en documentación.
- No hay request por Lead, describe en runtime ni N+1. Se conservan chunks y
  retries; la consulta SQL adicional de Campañas es una por chunk de 100.
- Las transacciones empiezan después de la llamada Salesforce y solo abarcan
  las dos escrituras locales. El `raw_payload` general completo nunca se
  reemplaza por el payload parcial de Campañas.

## Pruebas y verificaciones

- Baseline `php artisan test`: 795/795, 5.733 aserciones. Una primera pasada en
  competencia con metadata excedió el benchmark Stock; aislado pasó 2/2 (13) y
  la repetición completa secuencial quedó verde.
- Resolver final: 17/17, 80 aserciones.
- Focal sync + migración: 24/24, 221 aserciones.
- Regresión Leads/Campañas/Llamadas/Opportunities/Reservas/Comisiones: 143/143,
  1.069 aserciones.
- Suite completa final `php artisan test`: 816/816, 5.880 aserciones, correcta.
- Pint focal sobre los 10 PHP de esta tarea: correcto. El Pint global detecta
  deuda de formato preexistente en numerosos archivos ajenos; no se reformateó
  código fuera del alcance.
- `composer validate --no-check-publish --strict`: correcto.
- `composer audit --locked --no-dev`: sin vulnerabilidades conocidas.
- `npm run build`: correcto; avisos no bloqueantes ya conocidos de
  `login-bg.jpg` en runtime y deprecación Node. Se restauraron exclusivamente
  los artefactos generados porque no existe cambio frontend.
- `git diff --check`: correcto. Fallos introducidos: cero.

# Correctivo PR #27 — hidratación legacy de Campañas (2026-09-03)

- Se restauró exactamente la política histórica de
  `fillCampaignFieldsFromRawPayload()` para los doce campos ya consumidos en
  `main`: un valor local no válido según `CampaignValueNormalizer` puede
  recuperarse desde un raw legacy informado.
- Los campos incorporados por la migración permanecen en un bloque separado con
  política blank-only. Un placeholder nuevo no vacío sigue siendo autoritativo
  y no se sustituye desde `raw_payload`.
- El gate, WHERE, Meta Direct Form, first touch, claiming, Opportunities y la
  resolución efectiva de Fase 6 no cambian. No hay consultas, migraciones,
  dependencias, PII ni accesos externos nuevos.
- Regresión focal específica: 13 tests y 28 aserciones. Focal completo de
  Campañas: 97 tests y 748 aserciones. Suite completa: 856 tests y 6.155
  aserciones. Pint write + `--test` sobre los dos PHP del PR: correcto.
- No se ejecutaron backfills, sincronizadores reales ni escrituras en
  Salesforce, Meta o Google. No requiere acciones manuales adicionales al
  despliegue ordinario una vez aprobado el PR.

# Fase 6 — Migración de atribución de Campañas (2026-09-03)

## Resumen y decisiones

- Base utilizada: `origin/main` en
  `95d2254c2b8a9f0c31a71325e17bbea9732dddc5`. Rama:
  `feature/salesforce-campaign-attribution-migration`.
- El WHERE del sync y el gate del builder siguen siendo legacy. Los Leads UTM-only
  continúan excluidos y Meta Direct Form conserva su admisión por portal Meta más
  fuente legacy Facebook.
- Tras admitir el Lead, `SalesforceLeadFieldResolver` resuelve de forma
  independiente campaña, ID, fuente adquirida, medio adquirido y contenido.
  Cualquier nuevo no vacío gana, incluidos placeholders; solo null/vacío/
  whitespace usa fallback.
- Matching, first touch, claiming, deduplicación y ambigüedad no cambian de
  algoritmo. `matched_source_field` identifica el API Name ganador. La versión
  de regla pasa a `2026-09-03.1`.
- La clasificación efectiva para Meta usa `Fuente_origen__c` →
  `LEA_SEL_Fuente_Origen__c` después del gate. `source_acquired` y
  `medium_acquired` usan las parejas UTM adquiridas, no `LEA_SEL_*`.

## Archivos, base de datos y operación

- Producción: `CampaignAttributionBuilderService`.
- Pruebas: nueva regresión `CampaignAttributionFieldMigrationTest`.
- Documentación: auditoría Salesforce, informe de Campañas, documentación
  general, decisiones pendientes y este handoff.
- Sin migraciones, esquema, configuración, frontend ni dependencias. La lectura
  mantiene chunks y consultas existentes; la resolución es O(1) en memoria por
  Lead y no añade N+1 ni llamadas externas.
- `raw_payload` solo hidrata columnas vacías; no sustituye valores locales
  informados y no se copia a trazas. Los diagnósticos agregan campos ganadores
  por dimensión sin nombres, emails ni teléfonos.

## Validación y pendientes

- Baseline focal previo: 84 tests, 720 aserciones, correcto.
- Focal tras implementación: 94 tests, 742 aserciones, correcto.
- Suite completa: 853 tests, 6.149 aserciones, correcta. Pint se aplicó en modo
  write y `--test` quedó verde sobre los dos PHP modificados.
- Composer validate: correcto. Composer audit PHP: sin vulnerabilidades
  conocidas. Build Vite: correcto; los artefactos regenerados se restauraron al
  no existir cambio frontend. `git diff --check`: correcto.
- `npm ci` informó 5 advisories de desarrollo del lock actual (3 high, 2
  critical); `npm audit --omit=dev` informó 0 vulnerabilidades de producción.
  No se modificaron dependencias dentro de esta migración funcional.
- No se ejecutaron sincronizaciones reales, backfill ni escrituras en
  Salesforce/Google/Meta. Continúa pendiente validar con datos autorizados la
  semántica de `utm_id__c` por plataforma y medir el volumen UTM-only.

# Fase 5 — Procedencia nuevo → legacy en Reservas/Ventas (2026-09-03)

## Resumen y archivos

- `SalesforceOpportunitySyncService` prioriza `Lead.Fuente_origen__c` cuando
  una Opportunity no tiene `Portal__c` concluyente. El fallback específico se
  mantiene como `Portal_Text__c` → `LEA_SEL_Fuente_Origen__c` →
  `Fuente_Nuevo__c`.
- Se ampliaron las pruebas de resolución, sync, reproceso y fallback
  `leads_raw`, además de la documentación de auditoría, Reservas/Ventas,
  contraste general y decisiones pendientes.
- No se modificaron `OpportunityPortalNormalizer`, el comando de reproceso, el
  dataset, modelos, migraciones, esquema, frontend ni otros informes.

## Decisiones, seguridad y rendimiento

- `SalesforceLeadFieldResolver` es la autoridad para null/vacío/whitespace. Un
  valor nuevo no vacío, incluso placeholder o no normalizable, selecciona el
  Lead y bloquea legacy, fuente de Opportunity y fallbacks posteriores.
- `Opportunity.Portal__c` concluyente conserva prioridad absoluta. El matching
  por email/teléfono, `CreatedDate DESC`, chunks y todos los WHERE permanecen
  intactos; sync y reproceso comparten `resolvePortalForRecord()`.
- La traza añade exclusivamente fuente nueva, legacy, campos ganadores,
  fallback y conflicto. No añade teléfono, email, nombres ni payloads completos.
- `leads_raw` lee `Fuente_origen__c` desde `raw_payload`; no requiere columna ni
  consulta adicional. No hay N+1 ni consultas remotas por Opportunity.

## Base de datos, validación y operación

- Base: `origin/main` en `29210aae97fc329dc7bf41fc855a1c7e791e90ae`.
- Migraciones/configuración: ninguna. No requiere variables de entorno nuevas.
- PHP, Composer y Pint no estaban accesibles localmente al iniciar la tarea;
  la validación ejecutable queda registrada en la entrega final y deberá
  completarse en CI antes del merge.
- Baseline, pruebas focales, suite, Pint y Composer no se ejecutaron por esa
  limitación. La revisión estática de imports/constructores y
  `git diff --check` fueron correctas.
- No se ejecutaron sync Salesforce, reproceso histórico, backfill ni escrituras
  remotas. Acción posterior: abrir PR y validar CI; no ejecutar reproceso de
  datos como parte de esta fase.

## Riesgos pendientes

- La clasificación histórica no cambia hasta un reproceso posterior aprobado.
- `CampaignAttributionBuilderService` y las decisiones pendientes de matching
  temporal/ConvertedOpportunityId continúan expresamente fuera de alcance.

# Fase 3 — Clasificación nuevo → legacy de Leads (2026-09-02)

## Resumen

- Se resolvió el residuo de merge de este documento conservando completas las
  secciones de Tasador y coherencia UTM.
- `LeadClassificationResolver` compone el resolver central de campos nuevos
  con `LeadPortalResolver` como fallback legacy inalterado. Fuente, canal,
  medio y delegación priorizan sus nuevos raw persistidos únicamente cuando no
  son null, vacíos o whitespace.
- Sync, dashboard y el backfill local emplean la misma composición. El
  dashboard recalcula desde raw locales y no confía en `resolved_*` heredados.
  Los audit outputs incorporan raws, resolución, conflicto y fallback por
  dimensión. No se ejecutó el backfill.

## Alcance y seguridad

- Sin cambios de WHERE, universo de Leads, KPIs no clasificatorios,
  delegación comercial, Calls, Reservas/Ventas ni funcionalidad de Campañas.
- No se hicieron DML Salesforce, backfills históricos, migraciones, cambios de
  configuración ni consultas remotas durante la lectura del dashboard.

## Validación pendiente de entorno

- La sesión no expone un ejecutable PHP (ni `PATH` ni las rutas Herd locales
  habituales), por lo que las pruebas, Pint y verificaciones Composer quedan
  pendientes de ejecutarse en un entorno con PHP disponible antes de integrar.

## Corrección de revisión: autorización y trazabilidad

- La clasificación visible de delegación sigue usando nuevo → legacy, pero el
  scope de autorización usa una delegación interna calculada exclusivamente
  con la cadena legacy previa y el fallback contextual de Exposición. Así, un
  valor de `Delegacion_procedencia__c` no amplía ni retira permisos.
- La auditoría ahora muestra la delegación normalizada final y su origen
  efectivo. Cuando Exposición resuelve fuera de `field_resolution`, la traza
  identifica `persona_que_trabajo_delegation`, `owner_delegation` o
  `salesforce_users.user_delegation`, sin falsear un API Name Salesforce.
- Se añadieron regresiones de scope, fallback contextual, materialización del
  sync y backfill local. No se ejecutaron en esta sesión por la ausencia del
  runtime PHP/Composer; deben validarse en CI antes de integrar.

## Corrección CI PR #24: normalización y CSV

- El CI detectó expectativas no canónicas: `Madrid` y `HR MOTOR MADRID`
  normalizan a `Madrid General`; se corrigieron exclusivamente las assertions
  de Fase 3, sin cambiar el normalizador ni la autorización.
- El CSV de conciliación serializa las trazas de resolución estructuradas como
  JSON UTF-8 al emitir cada celda. Las respuestas JSON conservan los arrays y
  los booleanos CSV siguen siendo `Si`/`No`. No se afirma CI verde hasta que
  GitHub complete la nueva ejecución.

# Fase 4 — Procedencia nuevo → legacy de Llamadas (2026-09-02)

- Se añadió una política pura específica de Calls; no usa el resolver del
  dashboard de Leads porque su prioridad legacy es distinta. `Fuente_origen__c`
  gana únicamente en la clasificación visible cuando el fallback relacionado
  ya aplica; null/vacío/whitespace conservan `Portal_Text__c` →
  `LEA_SEL_Fuente_Origen__c` → `Fuente_Nuevo__c`.
- Sync y reproceso local comparten la política. El reproceso obtiene Leads por
  lote desde persistencia local y conserva la clasificación previa si falta un
  Lead necesario; no consulta Salesforce ni se ha ejecutado sobre datos reales.
- La clasificación operacional legacy permanece separada para origen, duración
  y overflow. Se incrementó `CallClassificationRules::VERSION` por el cambio
  de política visible. No cambiaron Task WHERE, equipos, delegaciones, zonas,
  estados, universos ni otros informes.
- Correctivo de revisión: si el reproceso necesita un Lead pero este no existe
  localmente, conserva `portal_resolved`, fuente de resolución y los cuatro
  valores operativos persistidos (`call_origin`, duración ajustada, overflow y
  motivo), dejando `lead_unavailable_locally` en la traza. Nunca usa el portal
  visible nuevo como sustituto del portal operativo legacy.

## Acciones manuales y pendientes

- En despliegue: aprobar/ejecutar migración y después sincronizar ventanas
  ordinarias según el runbook; esta tarea no ejecuta backfill histórico.
- Pendiente técnico: semántica de `utm_id__c` por plataforma, casos reales
  Google/Meta, medición UTM-only fuera del universo legacy y diseño del backfill.
- Pendiente de implementación: migrar conscientemente Calls,
  Reservations/Sales y `CampaignAttributionBuilderService` en tareas separadas.

## Confirmación

- Universos de Leads, Campañas, Opportunities y Tasks: NO modificados.
- Conteos y clasificación visible: NO modificados.
- `CampaignAttributionBuilderService`: NO modificado.
- Backfill, escritura Salesforce, producción y frontend: NO modificados.
- Campos legacy eliminados o reutilizados: NO.

# Desglose de compras Tasador (2026-09-01)

## Resumen y decisiones

- Desde junio de 2026, `buildAppraiserMonthlySummaryRow()` mantiene un único
  tramo calculado sobre el total conjunto de oportunidades `Tasacion` y
  `Cambio`, pero ahora publica también sus importes desglosados.
- `appraisals_amount` y `changes_amount` se calculan con el mismo rate y
  `purchases_amount` se deriva de su suma. La comisión final continúa usando
  exclusivamente `purchases_amount`, por lo que el correctivo no duplica ni
  modifica el importe pagado.
- No se modificaron tramos, universos, filtros, detalle de compras, lógica
  histórica anterior a junio, frontend ni contratos públicos.

## Archivos y operación

- Modificados: servicio del dashboard comercial, su prueba feature, cálculo
  funcional documentado y este handoff.
- Base de datos, migraciones, configuración, variables de entorno y assets: sin
  cambios. No requiere acciones manuales distintas del despliegue ordinario.

## Seguridad y rendimiento

- El cálculo reutiliza las operaciones ya cargadas, sin SQL, HTTP ni Salesforce
  adicionales. Las colecciones se separan una vez y mantienen complejidad O(n).
- No se incorporan IDs, nombres reales, credenciales ni datos de producción.

## Pruebas

- Regresiones focalizadas iniciales del Tasador: 5 pruebas y 52 aserciones,
  correctas. Cubren 8 Tasaciones + 2 Cambios, tipos aislados, tramo 0-7 y el
  escenario vigente con ventas, financiación y rapidez.
- Test exacto preexistente del Tasador: 1/1 y 15 aserciones. Dashboard completo:
  54/54 y 446 aserciones. API de Comisiones: 22/22 y 138 aserciones.
- Suite completa: 820/820 pruebas y 5.922 aserciones, correcta. Pint focal sobre
  los dos PHP modificados, lint PHP y `git diff --check`: correctos.

# Corrección de coherencia UTM en doble escritura (2026-09-01)

## Resumen

- Causa: el upsert secundario de Campañas mezclaba correctamente los diez raw
  de adquisición/UTM, pero no leía ni actualizaba `field_resolution`; una fila
  general podía conservar una resolución UTM calculada sobre valores antiguos.
- Solución: la lectura masiva existente incorpora `field_resolution`. Después
  de aplicar la preservación incoming no vacío → incoming / vacío → existente,
  recalcula los cinco nodos UTM con `SalesforceLeadFieldResolver` y los mezcla
  sobre el JSON general existente.
- Autoridad: Campañas solo puede sustituir `utm_campaign`, `utm_id`,
  `utm_source`, `utm_medium` y `utm_content`. Conserva exactamente `source`,
  `channel`, `medium`, `delegation`, cualquier otro nodo y `raw_payload`.
- Robustez: JSON nulo, parcial o inválido produce cinco nodos UTM válidos sin
  inventar dimensiones generales ni bloquear el chunk.
- Rendimiento/atomicidad: una única lectura por chunk de 100, upsert masivo,
  misma transacción corta y mismos retries; sin N+1 ni llamadas externas.

## Archivos y alcance

- Modificados: `CampaignLeadSyncService`, su test de caracterización, auditoría
  transversal y este handoff.
- Sin migraciones, configuración, frontend, rutas, Salesforce, backfill ni
  cambios en sync mensual, resolver central, attribution builder, Calls,
  Opportunities o Reservations/Sales.

## Validación

- Baseline antes del cambio: `php artisan test`, 816/816 y 5.880 aserciones.
- Focal Campañas + resolver: 24/24 y 205 aserciones.
- Suite completa final: 817/817 y 5.934 aserciones.
- Pint focal sobre los dos PHP modificados: correcto. Pint global conserva
  exclusivamente deuda preexistente en archivos ajenos; no se reformateó fuera
  de alcance.
- Composer validate: correcto. Composer audit: sin vulnerabilidades conocidas.
- Build Vite: correcto, con avisos preexistentes de imagen runtime y deprecación
  Node; artefactos regenerados restaurados al no existir cambio frontend.
- `git diff --check`: correcto. Fallos introducidos: cero.

# Fase 7A — Backfill histórico controlado de atribución Lead (2026-09-03)

## Resumen y archivos

- Se añadió el comando `salesforce:backfill-lead-attribution-fields`, su servicio
  por lotes, el modelo/migración de histórico y una suite feature dedicada.
- Se actualizaron la auditoría Salesforce, la guía general, el estado de
  decisiones pendientes, la decisión arquitectónica de backfill y este
  handoff. `PROJECT_CONTEXT.md` no cambia porque no se altera la estructura
  general de módulos ni una convención transversal de la aplicación.

## Decisiones y base de datos

- El universo se obtiene mediante la unión ordenada de IDs ya existentes y
  dentro del rango local en `salesforce_leads` y
  `campaign_salesforce_leads`. Salesforce solo recibe consultas `Lead WHERE Id
  IN (...)` sobre IDs 00Q validados.
- Se creó `salesforce_lead_attribution_backfill_history` porque los mecanismos
  existentes no guardaban before/after por fila para ambas tablas. Conserva
  run UUID, tabla, Salesforce ID, motivo, campos cambiados, valores anteriores/
  nuevos y fecha. No guarda PII ni payloads completos.
- Las escrituras son UPDATE masivos y transaccionales por chunk junto con el
  histórico. No existe ruta de insert de Lead. Los resolvers centrales calculan
  `field_resolution` y las materializaciones del dashboard.

## Seguridad y rendimiento

- `--from`/`--to` y un modo exclusivo son obligatorios; `--apply` requiere un
  motivo de al menos 10 caracteres. Dry-run no escribe tablas, histórico ni
  cachés.
- Se consultan solo Id y once campos de atribución, sin nombre, email o teléfono.
  El proceso usa lotes de 100, una consulta Salesforce por lote, lecturas SQL
  agrupadas, UPDATE masivo y transacción corta. No hay N+1 ni red desde HTTP.
- `raw_payload` conserva todas sus claves y solo fusiona las once consultadas;
  el histórico registra únicamente ese subconjunto. Las cachés concretas se
  versionan solo después de cambios aplicados.

## Pruebas y operación

- La suite nueva cubre validación de modo/rango/motivo, dry-run, apply,
  idempotencia, tablas general/especializada, IDs extra/ausentes/inválidos,
  UTM-only, cursor/limit, fallo Salesforce entre lotes y rollback conjunto ante
  error DB.
- Test específico: 9 pruebas y 74 aserciones, correcto. Bloque focal con
  resolvers, sync mensual y Campañas: 56 pruebas y 394 aserciones, correcto.
  Suite completa: 865 pruebas y 6.229 aserciones, correcta. Pint WRITE y
  `--test`, Composer validate/audit, build Vite y `git diff --check`: correctos.
  El build conserva los avisos preexistentes de imagen runtime y deprecación de
  Node; sus artefactos hash se restauraron porque no hay cambio frontend.
- La herramienta está preparada, pero el histórico todavía **NO ha sido
  modificado**. No se ejecutaron `--apply`, backfill real, sincronizadores,
  Calls, Opportunities, reconstrucción de Campañas ni escrituras Salesforce.
- Acción manual posterior: desplegar la migración, revisar primero una ventana
  con `--dry-run`, aprobar motivo/rango y ejecutar `--apply` únicamente mediante
  el runbook operativo correspondiente.

## Correctivo de consistencia previo al merge del PR #28

- La identidad usada para conciliar Salesforce IDs es ahora los primeros 15
  caracteres, conservando exactamente mayúsculas y minúsculas. Las variantes
  local 15 / respuesta 18 se reconocen como el mismo Lead; un cambio de casing
  en esos 15 caracteres continúa representando una identidad distinta.
- El cursor sigue ordenando el `salesforce_id` local literal. Dentro de cada
  lote, representaciones 15/18 equivalentes comparten una consulta lógica y
  cada fila local existente conserva su clave original.
- La consulta Salesforce permanece fuera de la transacción. En `--apply`, las
  filas objetivo se releen con `lockForUpdate()` y `ORDER BY salesforce_id`; solo
  después se fusiona el `raw_payload`, se recalcula la resolución y se construye
  el before/after. Así se preservan cambios concurrentes confirmados por el sync
  periódico y el histórico describe el estado realmente bloqueado.
- Un lock atómico de Laravel sobre el store de caché configurado protege apply
  frente a apply durante seis horas y se libera en `finally`. Dry-run no toma
  este mutex. El lock no sustituye el bloqueo transaccional frente a otros
  escritores.
- Las regresiones cubren equivalencia 15/18, sensibilidad a casing, coexistencia
  de representaciones entre tablas, cursor literal mixto, snapshot concurrente,
  before real y exclusión mutua. La herramienta sigue sin haberse ejecutado
  sobre datos reales y las escrituras Salesforce continúan siendo cero.
- Validación local: backfill 16/16 y 114 aserciones; bloque focal completo 63/63
  y 434 aserciones; suite completa 872/872 y 6.269 aserciones. Pint WRITE y
  `--test`, Composer validate/audit, build Vite y `git diff --check` correctos.
  El build solo emitió los avisos preexistentes y sus artefactos se restauraron
  porque este correctivo no incluye frontend. No hay migraciones nuevas.

# Fase 7B — Reproceso histórico seguro de portales de Opportunities (2026-09-03)

## Resumen y archivos

- Se endureció `reports:reprocess-opportunity-portals` y se extrajo la operación
  a `OpportunityPortalReprocessService`; no se creó un comando paralelo.
- Se añadieron el modelo y la migración de
  `salesforce_opportunity_portal_reprocess_history` y se amplió la suite feature
  del comando. También se actualizaron las guías de Reservas/Ventas, auditoría,
  decisiones y contexto general. `PROJECT_CONTEXT.md` no cambia porque el módulo
  y sus convenciones generales permanecen iguales.

## Decisiones, seguridad y base de datos

- El universo nace únicamente de `salesforce_opportunities` local y del rango
  `[from, to)`. Dry-run/apply son exclusivos; apply exige motivo, toma un mutex
  de seis horas y solo actualiza seis campos de atribución más `updated_at`
  cuando existe un cambio real.
- Salesforce se usa en lectura exclusivamente para el matching Lead agrupado ya
  existente. No se consulta Opportunity ni se invocan create/update remotos.
  No se registra PII en métricas, errores o histórico.
- La tabla nueva guarda run UUID, IDs técnicos, motivo, campos modificados,
  before/after y fecha. El debug se filtra por whitelist y no se guarda
  `raw_payload`. UPDATE e histórico comparten transacción por chunk.

## Concurrencia y rendimiento

- Los chunks son de 100 y avanzan por PK local. La consulta Lead ocurre antes de
  abrir la transacción. Las filas se releen ordenadas con `lockForUpdate()` y se
  valida una huella de todos los inputs de resolución; cualquier cambio fuerza
  reconsulta y reintento, con máximo de tres.
- No hay N+1, HTTP bajo locks, insert/upsert/delete de Opportunity ni
  `Cache::clear()`. La versión de caché se incrementa una vez si hubo cambios
  confirmados, incluso cuando falla un chunk posterior.

## Pruebas y operación

- Baseline previo: 34 pruebas y 166 aserciones, correcto. Suite focal ampliada:
  91 pruebas y 735 aserciones, correcta. Suite completa: 882 pruebas y 6.368
  aserciones, correcta. Pint WRITE y `--test`, Composer validate/audit, build
  Vite y `git diff --check`: correctos. El build solo emitió los avisos
  preexistentes y sus artefactos se restauraron al no existir cambio frontend.
  `npm ci` informó 5 advisories del lock actual (3 high, 2 critical); no se
  alteraron dependencias porque su remediación queda fuera de esta fase.
- La herramienta está preparada, pero el reproceso histórico **NO se ha
  ejecutado** y tampoco se ha realizado un dry-run productivo. Escrituras
  Salesforce: cero. Tras desplegar la migración, cualquier operación real
  requiere conciliación, aprobación y runbook separados.
