# Informe de Reservas / Ventas

Actualizado: 2026-08-06.

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
- Los porcentajes de conversión se calculan como
  `métrica / oportunidades de la misma fila`.
- La participación se calcula como
  `métrica de la fila / total de la métrica` y se muestra por separado.
- No se usa un benchmark de conclusiones hasta que exista una definición
  funcional aprobada.

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
