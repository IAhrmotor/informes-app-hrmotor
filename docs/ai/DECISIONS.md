# Decisiones técnicas

## 2026-08-06 — Cohorte única y auditoría minimizada

Se adopta una única resolución de cohorte por informe para alimentar KPI y
auditoría de Reservas/Ventas. Los decoradores de auditoría pueden enriquecer la
misma fila, pero no aplicar filtros funcionales adicionales.

La exportación estándar se considera un artefacto de conciliación, no una
extracción CRM: se retiran `Opportunity.Name`, `Account.Name` y datos de
contacto porque no son necesarios para reconstruir KPI o duplicados. Cualquier
consumidor futuro de PII requerirá un flujo distinto y aprobación explícita.

Para Llamadas, los valores estructurados se representan mediante JSON explícito
y la cardinalidad se define por `Task.Id`, incluidas las Tasks excluidas de KPI.
