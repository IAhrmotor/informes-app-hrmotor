# Contexto técnico del proyecto

Actualizado: 2026-08-06.

## Convenciones de exportación auditada

- Los CSV con valores compuestos deben usar `App\Support\CsvValueSerializer`;
  no deben pasar arrays u objetos directamente a `fputcsv`.
- Las exportaciones voluminosas deben escribir directamente al stream mediante
  cursor o lotes, con ámbitos resueltos en servidor antes de producir filas.
- KPI, JSON de auditoría y CSV deben consumir la misma resolución de cohorte.
- Los CSV estándar de auditoría no deben seleccionar datos personales que no
  sean imprescindibles para explicar la métrica.

