# Handoff para agentes

Actualizado: 2026-08-10.

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
