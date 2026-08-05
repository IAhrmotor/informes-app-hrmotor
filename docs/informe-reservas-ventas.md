# Informe de Reservas / Ventas

## Universo temporal

El criterio seleccionado define una unica cohorte para todo el informe:

- `created_date`: oportunidades creadas dentro del periodo;
- `reservation_date`: oportunidades cuya fecha de reserva esta dentro del periodo;
- `cv_signed_date`: oportunidades cuya fecha de firma esta dentro del periodo.

Una vez fijada la cohorte, todos los KPIs, porcentajes, comparativas, tablas y
auditorias se calculan sobre esas mismas oportunidades. Por ejemplo, una
oportunidad creada en julio y firmada en agosto forma parte de la cohorte de
julio cuando se selecciona Fecha de creacion. Una oportunidad creada en junio y
firmada en julio no forma parte de esa cohorte.

La pantalla muestra el criterio que define el universo, el periodo, el periodo
comparado y el corte de la fotografia local.

## Duplicados por vehiculo y fecha

Reservas y firmas se identifican por el ID Salesforce del vehiculo y, cuando no
esta disponible, por la matricula normalizada. Sin una referencia de vehiculo no
se deduplican oportunidades distintas.

Si dos o mas oportunidades tienen el mismo vehiculo y exactamente la misma
fecha de reserva o firma:

- el evento cuenta una sola vez en el KPI;
- se genera una alerta con vehiculo, fecha y Opportunity IDs;
- si propietario, tienda de entrega, delegacion, zona o portal discrepan, el
  evento se asigna a `Incidencia de datos` en ese desglose;
- no se usa `LastModifiedDate` ni el orden de consulta para adjudicarlo a una
  tienda o comercial;
- todas las filas permanecen en el CSV de auditoria, indicando grupo, tamano,
  registro contabilizado, conflictos y estado del desglose.

La eleccion tecnica de una fila como `counted_in_kpi` sirve unicamente para
reconstruir el recuento global. No convierte esa fila en fuente funcional para
los desgloses cuando existen datos contradictorios.

## Porcentajes y conclusiones

La conversion de una fila es `metrica / oportunidades de la fila`. La
participacion es `metrica de la fila / total de la metrica` y se presenta por
separado. No existe todavia un benchmark funcional aprobado, por lo que no se
utiliza uno para emitir recomendaciones.

## Despliegue

Este cambio no incorpora migraciones ni reprocesados. Tras desplegar codigo:

```bash
php artisan optimize:clear
npm ci
npm run build
```

La deduplicacion se calcula sobre la fotografia local existente. La validacion
en produccion debe contrastar por Opportunity ID una cohorte cerrada y los
grupos de calidad mostrados.
