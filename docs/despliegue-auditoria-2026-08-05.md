# Despliegue del lote de auditoría 2026-08-05

Revisado documentalmente: 2026-08-06.

No ejecutar desde Codex en produccion. Orden recomendado:

```bash
php artisan down
php artisan migrate --force
php artisan optimize:clear
npm ci
npm run build
php artisan up
```

Inicialización y comprobaciones posteriores, solo cuando correspondan al
entorno y exista aprobación:

```bash
php artisan stock:sync-salesforce-catalog
php artisan reports:reprocess-calls-classification --from=2026-07-01 --to=2026-07-31 --dry-run
php artisan campaigns:build-attribution --from=2026-07-01 --to=2026-08-01
php artisan stock:sync-daily --skip-vehicles --skip-opportunities --skip-logistics --skip-stock-snapshot --skip-alerts
```

Las firmas anteriores se contrastaron con `php artisan help`. `--to` es
exclusivo en Campañas e inclusivo en el reprocesado de Llamadas. La
reconstrucción de Campañas cambia first touch histórico y debe probarse primero
sobre copia de base y conciliarse por ID. No reprocesar Llamadas sin período,
simulación previa, motivo y export.

Rollback de codigo: desplegar la revision anterior y ejecutar `php artisan optimize:clear`. Rollback de esquema: hacer primero backup; `php artisan migrate:rollback --step=7` elimina cierres, snapshots, historiales y clasificaciones, por lo que solo debe usarse si las tablas nuevas no contienen auditoria que deba conservarse. Es preferible rollback de codigo manteniendo tablas aditivas.

Validar en producción:

- permisos positivos y negativos por cada rol, incluidos ámbitos y acceso del
  Auditor de comisiones a Penalizaciones;
- conciliaciones internas visibles únicamente para Administrador/IT;
- snapshot definitivo de un mes de prueba y bloqueo frente a cambios posteriores;
- suma de capacidad y plan sin sobreasignación de Stock;
- IDs eliminados/fusionados de Leads;
- `Respondido por`, `ABANDONED`, `Sin equipo` e historial de Llamadas;
- campañas `test` por ID, Salesforce-only y atribuciones ambiguas;
- una cohorte por criterio temporal y duplicados de reserva/firma.

Reservas/Ventas no añade migraciones ni backfill: su deduplicación se calcula al
construir el dataset local.
