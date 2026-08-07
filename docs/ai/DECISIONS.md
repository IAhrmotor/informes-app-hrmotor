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

## 2026-08-07 — Tipo Venta y contenido descriptivo

La clave funcional Venta agrupa Venta, Venta con cambio, Lead y Ayvens. Como el
tipo normalizado está materializado en `salesforce_leads`, la modificación se
acompaña de un comando de reproceso idempotente y con modo simulación; no se
reconstruyen automáticamente los snapshots de Campañas.

Reservas/Ventas no utiliza IA ni un contrato de recomendaciones. Sus
comparativas y porcentajes se limitan a información descriptiva de cohortes.
