# Informe de Llamadas

## Universo

Solo se incluyen Tasks de llamada con `CallObject`. Las Tasks `Type = Call` sin informacion de centralita se conservan en auditoria con `missing_call_object`.

## Clasificacion

- Atendida: resultado `ANSWERED` o `Respondido por` valido cuando no hay otro resultado.
- `ABANDONED`: siempre perdida y nunca desbordada.
- Desbordada: reglas versionadas de origen, portal, equipo y teclado.
- Duracion provisional: menos 5 segundos en directa y menos 10 en portal.
- Las llamadas operativas sin equipo aparecen como `Sin equipo`.

`classification_rule_version`, `classified_at`, valores brutos e historial permiten reproducir cada decision. Una modificacion real de Salesforce genera historial. Un cambio de parser no reprocesa por si solo el historico.

Reproceso controlado:

```bash
php artisan reports:reprocess-calls-classification --from=2026-07-01 --to=2026-07-31 --dry-run
php artisan reports:reprocess-calls-classification --from=2026-07-01 --to=2026-07-31 --reason="Motivo aprobado y documentado"
```

Auditoria del perfil que parece de pruebas, sin excluirlo:

```bash
php artisan reports:audit-calls-profile --profile="Pruebas comunidad comercial" --from=2026-07-01 --to=2026-07-31
```
