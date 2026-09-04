# Handoff del proyecto

Actualizado: 2026-08-06. Proyecto: `informes-app-hrmotor`.

## 1. Estado actual

La aplicación consolida Salesforce, Google Ads, Meta Ads y ficheros operativos
en seis informes: Leads, Reservas/Ventas, Llamadas, Campañas, Comisiones y Stock.
Los dashboards leen fotografías locales y deben mostrar período, fuente,
actualización y corte; no deben presentarse como consultas en vivo a Salesforce.

El árbol de trabajo contiene un lote amplio de cambios aún no consolidado en un
commit. Se han preservado. La documentación histórica principal se ha movido a
`docs/`; Git muestra los antiguos archivos raíz como eliminados y sus versiones
en `docs/` como nuevas. No recrear copias en la raíz.

Última verificación funcional registrada:

- suite completa: 393 pruebas, 2.667 aserciones;
- regresión final de permisos: 8 pruebas, 55 aserciones;
- regresión final de Reservas/Ventas: 2 pruebas, 21 aserciones;
- build Vite correcto.

Esta actualización es documental y no modifica código, esquema ni datos.

## 2. Stack y arquitectura

- PHP `^8.3`; runtime local utilizado: PHP 8.4.
- Laravel `^13.7`.
- Blade y JavaScript vanilla.
- Vite 8 y Tailwind/Vite.
- Pest 4 / PHPUnit.
- Salesforce REST API, Google Ads API y Meta Marketing API.

Flujo común:

```text
fuente externa
  -> comando/servicio de sincronización
  -> tablas locales y snapshots
  -> normalización/atribución/versionado
  -> servicio de dataset
  -> controlador JSON/export
  -> Blade/JavaScript
```

Las reglas funcionales deben vivir en servicios reutilizables, no en Blade ni
duplicadas entre sincronización, filtros y exportaciones.

## 3. Seguridad y acceso

La autorización principal está en `App\Support\ReportUserAccess` y en el
middleware `report.access`. Debe aplicarse a pantalla, JSON, exportación,
auditoría, filas y columnas; ocultar HTML no autoriza un endpoint.

| Rol | Ámbito actual |
|---|---|
| Administrador/IT | Completo; usuarios, configuración y conciliaciones internas. |
| Dirección | Informes y auditorías autorizados; aprueba y reabre cierres. |
| Área Manager | Leads, Reservas/Ventas, Llamadas y Comisiones; solo su zona. |
| Responsable de delegación | Leads, Llamadas y Comisiones; solo su delegación. `master_delegation_id` obligatorio. |
| Marketing | Leads y Campañas. |
| Financiero | Comisiones y vista financiera autorizada. |
| Comercial | Leads, Llamadas y Comisiones; solo su Salesforce User ID. |
| Auditor de comisiones | Comisiones y Penalizaciones financieras; sin usuarios, fórmulas ni cierres. |

Identidades estables: Salesforce User ID, ID normalizado de delegación y zona
configurada. Las conciliaciones internas de Campañas y Comisiones se muestran
solo a Administrador/IT.

## 4. Leads

- Período: `Lead.CreatedDate`.
- Venta: Venta + Venta con cambio. Lead y Ayvens quedan fuera.
- Tipo normalizado una vez mediante `LeadRecordTypeNormalizer`.
- Canal por `Medio_Nuevo__c`.
- Portal con prioridad distinta para llamada y formulario, conservando campo y
  valores brutos.
- Comercial efectivo: reglas específicas para Convertido, Descartado y resto.
- Elegible: usuario activo con perfil `Compra/Venta` o
  `Comerciales Partner Community`.
- Calidad visible: Sin comercial elegible, Sin delegación comercial y Sin
  clasificar. Los válidos siguen dentro del total.
- Eliminados/fusionados no suman en activos; ID anterior y `MasterRecordId`
  permanecen en conciliación.
- Las peticiones anteriores se cancelan al cambiar filtros.

Auditoría:

- `/informes/leads/data/kpi-audit`;
- `/informes/leads/export/kpi-audit.csv`;
- `/informes/leads/export/reconciliation-audit.csv`;
- `/informes/leads/data/lead-audit?ids[]=...`.

Documento: `docs/reglas-negocio-leads.md`.

## 5. Reservas / Ventas

- El selector de creación, reserva o firma define una única cohorte para todos
  los KPIs y desgloses.
- Venta = Opportunity Venta/Cambio.
- Reserva viva, Cerrada Perdida y CV firmado conservan sus reglas actuales.
- Una reserva o firma repetida por vehículo y fecha cuenta una vez.
- Si owner, tienda, delegación, zona o portal discrepan, el evento se muestra
  como Incidencia de datos; no se adjudica arbitrariamente.
- El CSV conserva todas las Opportunity IDs y la traza de deduplicación.
- Conversión de fila y participación de columna son métricas separadas.

Pendiente real: benchmark funcional de conclusiones.

Documento: `docs/informe-reservas-ventas.md`.

## 6. Llamadas

- Universo: Tasks de llamada con `CallObject`.
- Sin `CallObject`: auditoría `missing_call_object`, fuera de KPIs.
- Atendida: ANSWERED o `Respondido por` válido.
- `ABANDONED`: perdida, nunca atendida ni desbordada.
- Sin equipo aparece como fila explícita.
- Duración provisional: −5 segundos directa y −10 portal.
- Clasificación versionada (`2026-08-04.1`) con historial.
- Cambio de parser no reprocesa históricos automáticamente.
- Reproceso manual exige rango y `--dry-run` o `--reason`.
- `Pruebas comunidad comercial` no se excluye automáticamente.

Auditoría: `/informes/llamadas/export/audit.csv`.

Documento: `docs/informe-llamadas.md`.

## 7. Campañas

- Separa Google/Meta, Salesforce-only, prueba, pendiente, ambiguo y sin
  atribuir.
- Salesforce-only suma actividad Salesforce, no rendimiento de pago sin gasto.
- Tipo de campaña y RecordType del Lead son filtros independientes.
- First touch: relación explícita, IDs inequívocos, campaña original, primera de
  cuenta, Salesforce-only, ambiguo/sin atribuir.
- Cada entidad cuenta una vez; empates sin precedencia quedan ambiguos.
- Prueba se excluye solo por clasificación persistida por plataforma/cuenta/ID.
- La revisión prioriza fallo de medición, inversión sin resultados, coste fuera
  de benchmark y caída del funnel; dentro del nivel manda la inversión.
- `--window` existe por compatibilidad y no limita la atribución.

Auditorías:

- `/informes/campanas/export/kpi-audit.csv`;
- `/informes/campanas/export/campaigns.csv`;
- `/informes/campanas/export/attributions.csv`.

Documento: `docs/informe-campanas.md`.

## 8. Comisiones

- `CommissionMonthResolver` comparte mes entre seis pestañas y exports.
- Mes actual siempre Provisional.
- Estados: provisional, pendiente de aprobación, definitivo y reabierto.
- Snapshot definitivo reproducible con corte, fórmulas, fuentes y ámbitos.
- Dirección/IT aprueba y reabre; reapertura exige motivo.
- Cambios posteriores van al libro de ajustes del siguiente mes abierto o
  requieren reapertura; no sobrescriben un definitivo.
- Cada pestaña conserva su universo y puente
  `base - exclusiones + inclusiones = total`.
- Reseñas: CreatedDate del mes por OwnerId / operaciones elegibles; puede superar
  100 % y permanece sin cambio funcional.
- Auditor de comisiones puede cargar Penalizaciones financieras.

Documento: `docs/informe-comisiones.md`.

## 9. Stock

- Contexto/capacidad: Disponible + Reservado + Bloqueado.
- Traslados: todos los Disponibles operativos; 60/90 días son prioridad.
- Top 3 teórico y destino ejecutable diferenciados.
- El plan consume capacidad virtual y no sobreasigna ni crea movimientos.
- Catálogo canónico desde picklists activos de Salesforce; aliases solo enlazan
  variantes históricas.
- Venta válida: Venta/Cambio firmada, con fecha, vehículo y no Cerrada Perdida.
- Varias ventas: gana firma más reciente; empate exacto queda ambiguo y no suma.
- Matriculación no participa: falta API Name verificado.
- Ratios con stock cero son no disponibles.

Documento: `docs/informe-stock.md`.

## 10. Scheduler y comandos

El cron del servidor solo debe ejecutar `php artisan schedule:run` cada minuto.
Laravel usa ventanas móviles y bloqueos de solapamiento.

```text
cada 15 min  salesforce:sync-monthly-commercial --days=2
01:00         salesforce:sync-tasaciones --days=120
01:15         salesforce:sync-campaign-leads --days=120
01:30         campaigns:sync-meta --days=120
01:45         campaigns:sync-google --days=120
02:15         campaigns:build-attribution --days=120
03:15         reports:refresh-campaigns --days=120 --store
03:30         stock:sync-daily --sales-days=14 --logistics-days=30
04:45         salesforce:sync-calls --days=7
```

Comandos de inicialización o auditoría no automáticos:

```bash
php artisan stock:sync-salesforce-catalog
php artisan salesforce:backfill-lead-audit-metadata --dry-run
php artisan reports:reprocess-calls-classification --from=2026-07-01 --to=2026-07-31 --dry-run
php artisan reports:audit-calls-profile --profile="Pruebas comunidad comercial" --from=2026-07-01 --to=2026-07-31
```

En sincronizaciones Salesforce/Campañas, `--to` es normalmente exclusivo. En
el reproceso de Llamadas es inclusivo. Confirmar siempre con `php artisan help`
antes de producción.

La reparación manual `salesforce:repair-opportunity-lifecycle-dates` recupera
exclusivamente `CreatedDate`/`LastModifiedDate` para Opportunities locales cuyo
`created_date` sigue siendo NULL. Debe ejecutarse primero con `--dry-run`; el
modo `--apply` exige motivo, admite `--limit` y `--after-id`, usa mutex y deja
auditoría por ejecución. No forma parte del scheduler ni resincroniza datos
funcionales de Opportunity.

## 11. Migraciones del lote pendiente

Migraciones aditivas introducidas el 2026-08-05:

1. `2026_08_05_090000_create_commercial_commission_closures.php`;
2. `2026_08_05_100000_add_duplicate_resolution_to_sale_snapshots.php`;
3. `2026_08_05_110000_create_salesforce_call_classification_history.php`;
4. `2026_08_05_120000_create_campaign_operational_classifications.php`;
5. `2026_08_05_121000_add_first_touch_trace_to_campaign_attributions.php`;
6. `2026_08_05_130000_add_scoped_roles_to_report_users.php`;
7. `2026_08_05_140000_create_stock_salesforce_catalog.php`.

No ejecutar rollback de estas tablas si ya contienen cierres, snapshots,
historial o clasificaciones auditables. Se recomienda rollback de código
manteniendo el esquema aditivo.

## 12. Despliegue

Producción no tiene Node/npm. Los assets deben llegar construidos desde CI; no
se ejecuta `npm ci` ni `npm run build` en cPanel. El mantenimiento se decide por
el riesgo real de la migración; el lote transversal de 2026-08-11 requiere
ventana controlada por creación de índices sobre tablas voluminosas.

No usar `migrate:rollback --step=N` como rollback genérico. Preferir rollback de
código compatible o forward fix, y analizar cada rollback de esquema con backup
restaurable. No lanzar sync, reprocesados, backfills ni pruning real como parte
implícita. Runbook: `docs/operaciones-produccion.md`.

## 13. Pendientes y riesgos

Pendientes funcionales únicos: consultar
`docs/decisiones-negocio-pendientes.md`.

Riesgos operativos:

- reprocesar Campañas puede cambiar first touch histórico;
- reprocesar Llamadas puede cambiar clasificaciones históricas;
- resincronizar datos operativos puede modificar informes abiertos;
- cierres definitivos no deben alterarse sin reapertura;
- no ejecutar campañas/stock de alto volumen sin límite de memoria y copia de
  seguridad cuando corresponda;
- nunca documentar tokens, contraseñas, PII ni importes reales sensibles.

### Remediación P0 de credenciales y autenticación

- El login humano se resuelve únicamente contra usuarios activos de
  `report_users`; roles y ámbitos se recargan desde base de datos.
- `INFORMES_AUTH_*` es legado no operativo y debe eliminarse de la configuración
  de los entornos.
- `INTERNAL_REVIEWS_*` y `COMMISSIONS_API_*` no tienen fallbacks: deben
  suministrarse mediante variables de entorno o la integración falla cerrada.
- Las credenciales que estuvieron embebidas deben rotarse fuera del repositorio
  y actualizarse en el gestor de secretos y en los consumidores autorizados.
- Antes de desplegar, revisar el historial Git y los artefactos derivados para
  determinar el alcance de exposición. No reescribir el historial sin un plan
  aprobado y coordinado.

## 14. Índice documental

- `README.md`;
- `docs/Documentacion_general_informes_y_contraste_salesforce.md`;
- `docs/reglas-negocio-leads.md`;
- `docs/informe-reservas-ventas.md`;
- `docs/informe-llamadas.md`;
- `docs/informe-campanas.md`;
- `docs/informe-comisiones.md`;
- `docs/informe-stock.md`;
- `docs/decisiones-negocio-pendientes.md`;
- `docs/despliegue-auditoria-2026-08-05.md`.

## 15. Correctivo de auditoría y conciliación (2026-08-06)

- Llamadas: el CSV de conciliación escribe una cabecera y una fila por cada
  `Task.Id` del universo bruto autorizado, incluidas las exclusiones como
  `missing_call_object`. Usa cursor y serialización JSON explícita para valores
  estructurados.
- Llamadas: `Sin equipo` forma parte del desglose visible de atendidas y la suma
  por equipos reconcilia con el total bajo los mismos filtros y ámbitos.
- Reservas/Ventas: KPI y auditoría parten de una única resolución de cohorte;
  todas las Opportunity IDs, incluidas las filas no contabilizadas de grupos
  duplicados, permanecen auditables.
- Reservas/Ventas: el CSV estándar deja de seleccionar y exportar nombre de
  Opportunity, nombre de Account, teléfonos y correos de cliente.
- Ámbitos: los recortes por zona, delegación y Salesforce User ID se aplican en
  servidor antes de producir las filas. La matriz vigente de acceso a informes
  no se amplía.
- Diagnóstico seguro: `reports:debug-reservas-ventas --reconcile-cohort` compara
  conjuntos de Opportunity IDs sin mostrar PII.
- Base de datos: no hay migraciones ni reprocesos históricos.
- Verificación: lint, Pint, build y pruebas focalizadas correctos. La suite
  completa recorrió 405 tests en el primer intento (403 correctos y dos
  regresiones después corregidas); los reintentos finales quedaron bloqueados
  por el timeout ambiental de un handler HTTP de Guzzle antes del resumen.

## 16. P1 Leads y Reservas/Ventas (2026-08-07)

- Leads: Venta incluye Venta, Venta con cambio, Lead y Ayvens para todo el
  histórico. `record_type_normalized` está materializado en `salesforce_leads`;
  se añade un reproceso idempotente con `--dry-run`, sin ejecutarlo.
- Leads: los KPI de `Sin comercial elegible`, `Sin delegación comercial` y
  `Sin clasificar` disponen de auditorías específicas por Salesforce Lead ID.
- Leads: se corrigió la prioridad de los dos campos de delegación confirmados.
  El API Name del tercer campo funcional no está verificado y queda bloqueado;
  no se ha inferido un sustituto.
- Reservas/Ventas: el informe deja de invocar el servicio de IA y devuelve un
  contrato descriptivo sin recomendaciones ni prioridades. Reserva viva se
  documenta como estado, no como conversión.
- Base de datos: no hay migraciones ni cambios automáticos de datos.
- Verificación: `--filter=Lead` correcto (121 tests, 617 aserciones), pruebas
  focalizadas correctas, suite completa correcta (409 tests, 2.777 aserciones),
  Pint y `npm run build` correctos.

## 17. Corrección de fallback de Exposición (2026-08-07)

- La delegación por owner/persona trabajadora se habilita únicamente cuando el
  tipo normalizado del Lead es Exposición. El portal no habilita este fallback.
- Las prioridades de los campos de delegación y el fallback histórico se
  conservan. Sin migraciones ni reprocesos.

## 18. P1 Llamadas (2026-08-07)

- El perfil Salesforce exacto `Pruebas comunidad comercial` se excluye de KPI y
  desgloses con `excluded_test_profile`; permanece en auditoría. La precedencia
  de exclusión es `missing_call_object` y luego perfil de pruebas.
- La duración ajustada es definitiva: directa `max(bruta - 5, 0)` y portal
  `max(bruta - 10, 0)`, centralizada en `CallClassificationRules` para sync y
  reproceso. Versión `2026-08-07.1`.
- Los equipos no resueltos son `unassigned`/`Sin equipo`; no caen en Tasadores.
  Equipo, zona y delegación son independientes y soporte no genera geografías
  ficticias. No hay migraciones.
## 19. P1 Campañas: atribución y cierre de inversión (2026-08-07)

- Las atribuciones ambiguas y exclusiones históricas exactas siguen auditables y
  quedan fuera de KPIs. No hay exclusión parcial por nombre.
- Se añadió cierre mensual de inversión con snapshot versionado y reapertura con
  motivo, exclusivamente para Administrador/IT. Los resultados comerciales no se
  congelan. Requiere migración; no se ejecutan reconstrucciones históricas.
## Correctivo Salesforce-only de Campañas (2026-08-07)

- Salesforce-only aporta solo Leads/Oportunidades y ratio Lead-a-Oportunidad;
  métricas comerciales y económicas son no aplicables.
- `campaign_salesforce_leads` es la fotografía sincronizada por
  `salesforce:sync-campaign-leads`; el builder genera
  `campaign_lead_attributions` por periodo. El dry-run de tipos de Lead no
  escribe y debe preceder cualquier sincronización/reconstrucción histórica.
## Simulación histórica de Campañas (2026-08-07)

- Dry-run disponible para sincronización de Campaign Leads y builder de
  atribución. Ambos son no persistentes; el builder reporta muestras de IDs y
  cambios de campaña/ambigüedad. `became_unattributed` exige identidad previa de
  campaña; `removed_attribution` cuenta filas actuales ausentes de la simulación.
- La escritura histórica con `campaigns:build-attribution --from` exige motivo.

## 20. Invariancia Opportunity → Lead (2026-09-04)

- Reservas/Ventas consulta teléfonos a partir de su clave normalizada y mantiene
  la comparación final normalizada; otra Opportunity ya no puede incorporar un
  candidato que la original no obtendría procesada aisladamente.
- El fallback `leads_raw` se completa por email/teléfono sin resultado remoto.
  Los empates de `CreatedDate` se resuelven por `Lead.Id` ascendente.
- No cambian prioridad funcional, universo, conteos ni datos raw. No se ejecutó
  reproceso histórico ni se realizaron escrituras Salesforce.
