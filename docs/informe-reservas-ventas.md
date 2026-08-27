# Informe de Reservas / Ventas

Actualizado: 2026-08-25.

## Fuente y datos locales

- Fuente principal: Salesforce `Opportunity`, con relaciones a `Account`,
  `Owner`, `RecordType` y `Product2`.
- Procedencia: Lead relacionado por señales inequívocas y fallback local
  documentado.
- Tabla principal: `salesforce_opportunities`.
- Fechas sincronizadas: `CreatedDate`, `OPO_FEC_Fecha_de_reserva__c` y
  `Fecha_firma_contrato__c`.
- Vehículo: `OPP_BUS_Vehiculo_de_interes__c` y matrícula de la relación ya
  sincronizada.

## Cohorte temporal

El selector de fecha define una única cohorte para todo el informe:

| Criterio | Campo local | Campo Salesforce |
|---|---|---|
| Fecha de creación | `created_date` | `CreatedDate` |
| Fecha de reserva | `reservation_date` | `OPO_FEC_Fecha_de_reserva__c` |
| Fecha de firma | `cv_signed_date` | `Fecha_firma_contrato__c` |

Después de fijar la cohorte, todos los KPIs, porcentajes, comparativas, tablas y
auditorías se calculan sobre esas mismas oportunidades. Una oportunidad creada
en julio y firmada en agosto cuenta como firmada dentro de la cohorte de julio
cuando el criterio es Fecha de creación. Una oportunidad creada en junio y
firmada en julio queda fuera de esa cohorte.

El KPI y la auditoría CSV resuelven la cohorte mediante el mismo dataset base y
los mismos filtros y ámbitos de servidor. La exportación decora ese conjunto sin
aplicar exclusiones funcionales adicionales: para `Oportunidades totales`, el
conjunto de Opportunity IDs del KPI y el del CSV debe ser idéntico.

La pantalla muestra criterio, período, período comparado, actualización y corte
de la fotografía local. Al cambiar filtros se cancela la petición anterior y se
ocultan los resultados obsoletos.

## Reglas de KPI

- `Venta` = `RecordType.Name` Venta o Cambio.
- Reserva viva = reserva true, contrato CV no firmado y etapa distinta de
  `Cerrada Perdida`.
- Caída = etapa `Cerrada Perdida`.
- CV firmado = flag firmado true y etapa distinta de `Cerrada Perdida`.
- `Reservas vivas actuales Salesforce` aplica la regla de reserva viva sin
  filtro de fecha, manteniendo tipo y filtros operativos.
- Los porcentajes de desglose se calculan como
  `métrica / oportunidades de la misma fila`; describen la proporción de la
  cohorte o fila, no una valoración de rendimiento.
- Reserva viva es el estado actual de una operación; no es una conversión ni
  una tasa de éxito. Una operación puede dejar de estar viva al avanzar.
- La participación se calcula como
  `métrica de la fila / total de la métrica` y se muestra por separado.
- No se usa un benchmark de conclusiones hasta que exista una definición
  funcional aprobada.

El informe es descriptivo: conserva valores, participaciones y diferencias
numéricas entre períodos, pero no emite recomendaciones, prioridades,
diagnósticos evaluativos ni llamadas externas de IA. No incorpora conversiones
7/14/30 días.

## Duplicados por vehículo y fecha

La identidad usa primero el ID Salesforce del vehículo y, si falta, la matrícula
normalizada. Sin ID ni matrícula no se deduplican oportunidades diferentes.

Si dos o más oportunidades comparten vehículo y exactamente la misma fecha de
reserva o firma:

- el evento cuenta una sola vez en el KPI;
- se genera una alerta con vehículo, fecha y Opportunity IDs;
- si propietario, tienda de entrega, delegación, zona o portal discrepan, el
  evento se asigna a `Incidencia de datos` en el desglose afectado;
- no se usa `LastModifiedDate` ni el orden de consulta para elegir un comercial
  o una tienda;
- todas las filas permanecen en la auditoría.

`counted_in_kpi` identifica técnicamente la fila que reconstruye el recuento
global, pero no la convierte en atribución funcional cuando hay campos
contradictorios.

## Portal y agrupaciones

El portal se resuelve con la regla centralizada de Opportunity y Lead
relacionado. Se conservan portal original, portal final, fuente de resolución y
Lead utilizado. Delegación y zona proceden del owner y usan la misma
normalización territorial que los informes comerciales.

## Auditoría y permisos

- JSON: `/informes/reservas-ventas/data/kpi-audit`.
- CSV: `/informes/reservas-ventas/export/kpi-audit.csv`.

El CSV incluye Opportunity ID, vehículo, matrícula, fechas, RecordType, etapa,
propietario, tienda, delegación, zona, cuenta, portal, grupo duplicado, tamaño,
fila contabilizada, Opportunity IDs afectados, campos contradictorios y estado
del desglose.

Las tablas de zonas, delegaciones, comerciales y portales incluyen siempre el
número absoluto de oportunidades de la fila. Ese valor constituye el contexto
de muestra de los porcentajes mostrados; no se ocultan filas ni se aplican
umbrales de suficiencia.

La exportación estándar no selecciona ni publica nombre de Opportunity, nombre
de Account, teléfono, móvil ni correos de cliente. `Opportunity.Name` se ha
retirado porque puede contener identidad del cliente y no es necesario para la
conciliación; se conservan `Opportunity ID` y los identificadores operativos no
personales necesarios. Los nombres de responsables internos se mantienen para
explicar la atribución y sus conflictos.

Todas las Opportunity IDs de un grupo duplicado permanecen como filas
auditables. `counted_in_kpi` solo identifica la fila técnica que suma el evento;
el resto del grupo no se elimina del CSV.

## Operación

```bash
php artisan salesforce:sync-opportunities --days=60
php artisan salesforce:sync-opportunities --from=2026-07-01 --to=2026-08-01
php artisan reports:debug-reservas-ventas
php artisan reports:debug-reservas-ventas --reconcile-cohort --from=2026-07-01 --to=2026-07-31 --date-criterion=created_date --opportunity-type=all
php artisan reports:reprocess-opportunity-portals
```

La conciliación muestra únicamente cantidades y diferencias `A - B` / `B - A`
por Opportunity ID; no muestra datos de contacto. Debe ejecutarse en el entorno
que contenga la fotografía a investigar para identificar discrepancias reales.

La deduplicación se calcula al construir el dataset; no necesita migración ni
backfill. Reprocesar portales puede cambiar históricos y debe conciliarse antes
por Opportunity ID.

Archivos principales:

- `app/Services/Reports/ReservationsSales/Sync/SalesforceOpportunitySyncService.php`;
- `app/Services/Reports/ReservationsSales/ReservationsSalesDashboardDatasetService.php`;
- `app/Services/Reports/ReservasVentas/OpportunityPortalNormalizer.php`.

## Pestaña Rendimiento comercial

Esta pestaña es independiente de la cohorte de las tres pestañas legacy. Solo
Administrador y Director/Dirección pueden ver la pestaña, consultar
`GET /informes/reservas-ventas/data/commercial-performance`, consultar la
auditoría `GET /informes/reservas-ventas/data/commercial-performance/audit` o actualizar
`PUT /informes/reservas-ventas/data/commercial-performance/target`. Ambos
controles se aplican en servidor; el `PUT` conserva CSRF y valida mes `Y-m` y
objetivo entero mayor que cero.

El informe mantiene un único bloque físico de filtros. Las tres pestañas legacy
presentan período, criterio de fecha, tipo de oportunidad y los controles
compartidos de delegación, zona y comercial. Al activar Rendimiento comercial,
ese mismo bloque oculta los controles de cohorte y muestra mes natural y la
edición del objetivo; zona, delegación y comercial conservan los mismos IDs DOM.
Cada cambio despacha únicamente el dataset del modo activo. Si una opción no
existe al cambiar de universo, se limpia y se repite una sola carga consistente.
Limpiar Rendimiento restablece mes y filtros organizativos, pero nunca modifica
el objetivo persistido.

### Actividad mensual y fórmulas

Cada hito pertenece a su propio mes natural `Europe/Madrid`:

| KPI | Universo/fecha local | Fórmula |
|---|---|---|
| Leads | Venta, Venta con cambio, Lead y Ayvens; `fecha_asignacion` | recuento por comercial efectivo consolidado |
| Oportunidades | `record_type_name IN (Venta, Cambio)`; `created_date` | recuento de Opportunity |
| Reservas totales | reserva true; `reservation_date` | evento deduplicado por vehículo+fecha |
| Reservas activas | reserva del mes, CV false y no Cerrada Perdida | evento deduplicado por vehículo+fecha |
| Ventas | CV true, no Cerrada Perdida; `cv_signed_date` | evento deduplicado por vehículo+fecha |
| Cancelaciones | reserva previa y transición demostrable a Cerrada Perdida | `salesforce_opportunity_stage_transitions.transitioned_at` |
| Margen | ventas del mes; `informe_rentabilidad` | suma solo valores informados |

- Lead → Reserva = reservas totales / leads asignados × 100.
- Oportunidad → Reserva = reservas totales / oportunidades creadas × 100.
- Reserva → Venta = ventas firmadas / reservas totales × 100.
- Cancelación = transiciones verificadas / reservas totales × 100.
- Cumplimiento = reservas totales / objetivo mensual × 100.
- Margen medio = margen informado / ventas con margen informado.

Un denominador cero devuelve `NULL`/N/A, nunca infinito ni 0 % ficticio. Como
son ratios de actividad y no de cohorte, pueden superar el 100 %. El objetivo
default inicial es 18; cada mes consultado se materializa inmediatamente en
`commercial_performance_monthly_targets`, aunque no haya sido editado, para que
un cambio futuro de la constante no reescriba el histórico. Cambiar un mes no
reescribe los demás. Semáforo: verde ≥100,
amarillo ≥80, naranja ≥60 y rojo por debajo de 60, exclusivamente sobre
cumplimiento.

La migración usa nombres MySQL explícitos para los dos identificadores que
excederían el límite de 64 caracteres con la convención automática:
`commercial_perf_target_updated_user_fk` y `sf_opp_stage_history_uq`. La FK
conserva `ON DELETE SET NULL` y el ID de historial continúa siendo único.

En resumen y evolución, el objetivo agregado es la suma de los objetivos de las
personas comerciales incluidas tras aplicar los filtros. Una fila de incidencia
puede conservar eventos operativos, pero no recibe objetivo, cumplimiento,
semáforo ni ranking y no amplía el denominador agregado.

Existe una sola fila y un solo objetivo por `commercial_id` y mes. La delegación
solo habilita comparaciones: cualquier hueco o cambio de delegación dentro del
mes mantiene la actividad individual agregada, pero la marca como `Histórico no
certificable`, sin escoger equipo arbitrariamente. El roster inicial incluye a
todo comercial cuyo único intervalo certificado cubre el mes completo —o el
corte transcurrido del mes actual—, aunque tenga cero actividad.

El filtro Comercial incluye identidades comerciales válidas aunque su delegación
mensual no sea certificable. Zona y Delegación solo ofrecen asignaciones
observadas o con bootstrap aprobado. El ranking se construye antes de aplicar el
filtro Comercial; los empates exactos comparten posición y no usa margen,
cancelación ni scoring compuesto. Las medias internas de equipo se conservan en
el contrato por compatibilidad, pero ya no se muestran en la interfaz.

### Cancelaciones verificadas

La investigación de solo lectura del 25/08/2026 confirmó:

- `OpportunityFieldHistory` es consultable pero devolvió cero filas;
- `OpportunityHistory` devolvió historial y, en cinco operaciones reales
  reservadas, secuencias explícitas hacia `Cerrada perdida`;
- una operación mostró `Reserva → Cerrada perdida → Presupuesto → Reserva →
  Cerrada perdida`, por lo que se preservan transiciones distintas;
- toda transición demostrable conserva estado de calidad; solo cuenta como
  cancelación si existe reserva y `reservation_date <= transitioned_at`.

Cada consulta correcta a `OpportunityHistory` materializa su intervalo en
`salesforce_opportunity_history_sync_intervals`. El mes actual se evalúa desde
su inicio hasta el último cutoff realmente consultado y certificado, que se
muestra en la interfaz. El sync captura un `observationCutoff` al comenzar,
recorta el rango solicitado a ese instante y no persiste intervalos futuros; el
cutoff nunca avanza por el reloj del dashboard. Los meses cerrados sí exigen el mes natural completo.
Solo la unión continua de intervalos aptos para KPI permite mostrar cancelaciones. Sin
cobertura o con cobertura parcial, recuento, porcentaje y comparación devuelven
N/A; cero significa cobertura certificada sin transiciones aplicables.

Una transición cuya Opportunity no existe en la réplica activa una recuperación
directa por Salesforce ID, en lotes de 100, reutilizando el SELECT y la
persistencia canónica de `SalesforceOpportunitySyncService`; después se
reclasifica en la misma ejecución. Si Salesforce no devuelve el ID, se conserva
`opportunity_not_local`. El intervalo guarda el número de dependencias y queda
`is_kpi_certified=false`: nunca puede producir cero cancelaciones. `reservation_date` permanece NULL
cuando no se conoce; no se fabrica una fecha. Una candidata Cerrada Perdida sin
etapa inmediatamente anterior demostrable se conserva como
`previous_stage_not_demonstrated` y también bloquea la certificación. Una fila
posterior cuya etapa previa ya era Cerrada Perdida no representa una transición
nueva y no bloquea por sí sola.

`CloseDate` y `LastModifiedDate` no se guardan ni consultan como fecha de
cancelación. `LastModifiedDate` se usa exclusivamente en la sincronización
incremental para descubrir Opportunities antiguas modificadas.

### Delegación histórica

La metadata Salesforce acreditó que `Delegacion_del_propietario__c` es una
fórmula calculada desde `Owner.USR_SEL_Delegacion__c`; no es histórica.
`UserFieldHistory` no está disponible y el tracking consultado de delegación no
devolvió cambios. Por ello:

- `commercial_delegation_snapshots` crea intervalos observados desde la
  implantación y los cierra al cambiar delegación, desactivarse el usuario o
  abandonar los perfiles comerciales. Para detectar este último caso, el sync
  refresca por ID solo usuarios ya conocidos aunque hayan salido del filtro
  comercial; conserva el `IsActive` real para no alterar consumidores compartidos;
- por decisión de negocio, la primera asignación observada fiable y sin
  contradicciones puede extenderse en un intervalo cerrado desde
  `2026-04-01 00:00 Europe/Madrid`. El source
  `business_bootstrap_2026_04` evita presentarlo como observación Salesforce;
  no se modifica ninguna fecha real y una segunda ejecución explícita no duplica filas.
  Esta materialización solo se solicita manualmente con
  `--bootstrap-performance-history`; la captura normal nunca la ejecuta ni
  retroatribuye a un comercial observado después. Además, una reejecución
  explícita solo acepta usuarios cuyo primer snapshot observado coincide con el
  mínimo `observed_from` de la fotografía inicial. Los posteriores se informan
  como `not_initial_cohort` y quedan excluidos permanentemente del bootstrap;
- un cambio posterior de delegación/zona genera una alerta operacional `low`
  durable y deduplicada, además de cerrar/abrir los intervalos. Si ocurre dentro
  del mes, se muestra una nota ámbar y no se escoge una delegación mensual;
- la identidad de un usuario cuyo perfil actual ya no es comercial se sigue
  cargando cuando sus snapshots solapan el período consultado. El snapshot, no
  el perfil presente, acredita su pertenencia histórica; después del cierre no
  se incorpora como miembro cero de meses futuros;
- la pertenencia mensual exige cobertura continua y una sola delegación;
- los eventos de meses con huecos o cambios se muestran como `Histórico no certificable`, sin
  media de delegación ni ranking y sin aplicar la delegación actual hacia atrás;
- los usuarios inactivos permanecen en el dataset si tienen actividad e
  intervalos históricos.

Todos los hitos usan el mismo universo de responsables. Un owner de API,
Administración, Marketing, Area Manager u otro perfil no comercial sin snapshot
histórico aplicable se atribuye a `Incidencia de datos`: permanece auditable como
`non_commercial_responsible`, pero no participa en objetivos, medias ni ranking.
Un usuario actualmente no comercial sí conserva los meses acreditados por sus
snapshots históricos.

### Sincronización y despliegue

El scheduler ejecuta diariamente a las 07:10 (`Europe/Madrid`):

```bash
php artisan salesforce:sync-opportunities --days=2 --modified
```

La franja queda fuera de atribución de Campañas (02:15), refresco (03:15), Stock
(03:30, también escribe Opportunities) y el bloque SEO (05:15–06:30). La
captura de delegaciones pertenece exclusivamente al sync mensual cada 15
minutos y el scheduler usa `--days=2` sin la opción de bootstrap. La ventana incremental consulta `LastModifiedDate`, actualiza la
fotografía local y sincroniza `OpportunityHistory` del mismo intervalo.

Procedimiento de despliegue, sin ejecución automática:

```bash
php artisan migrate:status
php artisan salesforce:sync-monthly-commercial --days=2 --bootstrap-performance-history
php artisan salesforce:sync-opportunities --from=2026-04-01 --to=2026-05-01
php artisan salesforce:sync-opportunities --from=2026-05-01 --to=2026-06-01
php artisan salesforce:sync-opportunities --from=2026-06-01 --to=2026-07-01
php artisan salesforce:sync-opportunities --from=2026-07-01 --to=2026-08-01
php artisan salesforce:sync-opportunities --from=2026-08-01 --to=<día-posterior-al-cutoff>
```

Primero se enumeran y aprueban todas las migraciones pendientes; no se ejecuta
`migrate --force` hasta aprobar el lote. Tras migrar, el comando mensual con
`--bootstrap-performance-history` se ejecuta una sola vez: se concilian
snapshots observados, bootstrap creados/existentes, omitidos y conflictos. A
partir de ahí actúa el scheduler normal sin la opción. Cada backfill mensual se ejecuta y valida por separado,
registrando filas, duración, llamadas Salesforce, transiciones válidas,
`opportunity_not_local`, `previous_stage_not_demonstrated`, cobertura e
`is_kpi_certified`. No es necesario ampliar el inicio para buscar reservas
anteriores: las Opportunities candidatas ausentes se resuelven por ID. Si quedan
dependencias, se documenta la causa y se repite únicamente el intervalo afectado
después de resolverla. `--to` continúa siendo un límite exclusivo.

La disponibilidad retrospectiva de `OpportunityHistory` depende de la retención
real de Salesforce. Las candidatas sin etapa previa demostrable quedan fuera de
cancelaciones, se persisten como incidencia y mantienen el intervalo no
certificado. Las dependencias se calculan desde el estado actual de las
transiciones: cuando una reejecución resuelve la calidad y una cobertura
certificada solapada completa el rango, la deuda antigua deja de mostrarse. El
render, los filtros, medias, ranking y evolución leen únicamente BD
local y no incluyen PII de clientes. La auditoría específica muestra IDs de
Lead/Opportunity/historial, hitos, responsable, delegación/cobertura, clave de
deduplicación e incidencias/exclusiones, con paginación limitada a 200 filas.
La procedencia de cada atribución se expone como `delegation_status`:
`observed` (Observada), `bootstrap_approved` (Bootstrap aprobado) o
`not_certifiable` (No certificable), con `delegation_issue` cuando corresponde.
Se conserva `delegation_certified` por compatibilidad, pero la interfaz no llama
certificación Salesforce al bootstrap. Calidad publica
`delegation_history_evaluable_from`, `delegation_history_observed_from` y
`delegation_history_bootstrap_from`; el campo histórico
`delegation_history_certified_from` permanece solo por compatibilidad.
