# Despliegue y recuperabilidad

Esta guía histórica queda reemplazada por el runbook transversal vigente:
[operaciones-produccion.md](operaciones-produccion.md).

Principios obligatorios:

- producción es PHP 8.4 + MariaDB 10.6 + cPanel, sin Node/npm;
- `public/build` llega compilado desde el artefacto validado por CI;
- no se usa `migrate:rollback --step=N` como rollback genérico;
- primero se verifica el backup, después se migra;
- ante fallo se prefiere rollback de código si el esquema es compatible o un
  forward fix; un rollback de esquema exige análisis explícito por migración;
- no se ejecutan reprocesados, sincronizaciones ni pruning real como parte
  implícita del despliegue.
