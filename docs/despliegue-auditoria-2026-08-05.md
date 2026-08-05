# Despliegue auditoria 2026-08-05

No ejecutar desde Codex en produccion. Orden recomendado:

```bash
php artisan down
php artisan migrate --force
php artisan optimize:clear
npm ci
npm run build
php artisan up
```

Inicializacion y comprobaciones posteriores:

```bash
php artisan stock:sync-salesforce-catalog
php artisan reports:reprocess-calls-classification --from=2026-07-01 --to=2026-07-31 --dry-run
php artisan campaigns:build-attribution --from=2026-07-01 --to=2026-08-01
php artisan stock:sync-daily --skip-vehicles --skip-opportunities --skip-logistics --skip-stock-snapshot --skip-alerts
```

Las firmas anteriores se han contrastado con `php artisan help`. `--to` es exclusivo en Campanas e inclusivo en el reprocesado de Llamadas. La reconstruccion de Campanas cambia first touch historico y debe probarse primero sobre copia de base y conciliarse por ID. No reprocesar Llamadas sin periodo, motivo y export previo.

Rollback de codigo: desplegar la revision anterior y ejecutar `php artisan optimize:clear`. Rollback de esquema: hacer primero backup; `php artisan migrate:rollback --step=7` elimina cierres, snapshots, historiales y clasificaciones, por lo que solo debe usarse si las tablas nuevas no contienen auditoria que deba conservarse. Es preferible rollback de codigo manteniendo tablas aditivas.

Validar en produccion: permisos por cada rol, snapshot definitivo de un mes de prueba, suma de capacidad de Stock, IDs eliminados/fusionados de Leads, `Respondido por`/`ABANDONED`, campañas test por ID y conciliacion de ambiguos.

Para el lote posterior de Reservas/Ventas no hay migraciones ni backfill. Debe
validarse una cohorte por cada criterio temporal, un duplicado de reserva y uno
de firma; tambien el acceso del Auditor de comisiones a Penalizaciones y la
ocultacion de conciliaciones internas para perfiles distintos de Administrador/IT.
