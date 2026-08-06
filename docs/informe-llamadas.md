# Informe de Llamadas

Actualizado: 2026-08-06.

## Universo

- Fuente: Salesforce `Task` con `Type = Call`.
- El dashboard incluye únicamente llamadas con `CallObject`, es decir, llamadas
  con información de centralita asociada.
- Las Tasks de llamada sin `CallObject` se conservan para conciliación con
  motivo `missing_call_object`, pero no suman en los KPIs operativos.
- Fecha pivote: `Task.CreatedDate`.
- Tablas: `salesforce_calls`, `salesforce_users`, `call_agent_mappings` y
  `salesforce_call_classification_history`.

## Clasificación vigente

Versión de reglas: `2026-08-04.1`.

- Atendida: resultado normalizado `ANSWERED` o valor válido extraído de
  `Respondido por`, salvo una regla más fuerte de no atención.
- `ABANDONED`: siempre perdida, nunca atendida y nunca desbordada, aunque la
  descripción contenga otros valores.
- No atendida: resto de clasificaciones no atendidas.
- Origen directo: llamada de centralita/directa sin portal operativo.
- Origen portal: llamada asociada a un portal.
- Desbordada: llamada de portal atendida por Contact Center o Atención al
  Cliente, respetando portal y opción de teclado. `ABANDONED` queda excluida de
  esta regla.
- Las llamadas operativas sin equipo aparecen como `Sin equipo`; la suma de
  equipos debe reconciliar con el total correspondiente.

El parser extrae de `Description` resultado, `Respondido por`, agente, cola,
opción de teclado, duración y demás valores brutos. Esos valores se conservan
en la auditoría junto con la interpretación final.

## Duración

Regla provisional vigente:

- llamada directa: `max(duración base - 5, 0)`;
- portal: `max(duración base - 10, 0)`.

La duración base usa `CallDurationInSeconds` y la descripción como fallback. No
debe modificarse esta regla sin contrastar una muestra de Salesforce y
centralita; sigue siendo una decisión funcional pendiente.

## Equipos y perfil de pruebas

La clasificación usa usuario operativo, aliases explícitos y perfiles. Las
identidades de sistema no se mezclan con los equipos operativos.

`Pruebas comunidad comercial` no se excluye automáticamente. Debe auditarse:

```bash
php artisan reports:audit-calls-profile --profile="Pruebas comunidad comercial" --from=2026-07-01 --to=2026-07-31
```

El resultado debe revisarse por User ID, nombre, volumen, origen, equipo y
ejemplos antes de aprobar una exclusión o reasignación.

## Versionado e histórico

Cada llamada conserva clasificación, versión, fecha, valores brutos y motivo de
inclusión/exclusión. Si Salesforce cambia el registro original, la
sincronización puede reclasificarlo y guarda la clasificación anterior en
`salesforce_call_classification_history`.

Un cambio de parser no reprocesa históricos automáticamente. El reproceso es
manual, exige período explícito y `--dry-run` o motivo:

```bash
php artisan reports:reprocess-calls-classification --from=2026-07-01 --to=2026-07-31 --dry-run
php artisan reports:reprocess-calls-classification --from=2026-07-01 --to=2026-07-31 --reason="Motivo aprobado y documentado"
```

En este comando `--to` es inclusivo. Antes de un reproceso real se recomienda
exportar la auditoría y revisar el resumen del modo simulación.

## Auditoría y operación

- CSV: `/informes/llamadas/export/audit.csv`.
- Diagnóstico: `php artisan reports:debug-calls`.
- Sincronización: `php artisan salesforce:sync-calls --days=120`.

El CSV contiene Task ID, inclusión/exclusión, resultado bruto e interpretado,
duraciones, segundos descontados, portal, equipo, usuario, valores del parser,
regla, versión y corte.

Archivos principales:

- `app/Services/Reports/Calls/SalesforceCallSyncService.php`;
- `app/Services/Reports/Calls/CallDescriptionParser.php`;
- `app/Services/Reports/Calls/CallClassificationRules.php`;
- `app/Services/Reports/Calls/CallDashboardDatasetService.php`;
- `app/Console/Commands/ReprocessCallsClassificationCommand.php`.
