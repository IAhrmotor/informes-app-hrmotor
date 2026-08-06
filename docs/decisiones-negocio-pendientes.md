# Decisiones de negocio pendientes

Actualizado: 2026-08-06.

Este documento contiene exclusivamente decisiones que el código no puede tomar
sin una definición funcional o una validación externa. Las decisiones ya
implantadas se resumen al final y no deben volver a tratarse como pendientes.

## Pendientes reales

### Leads

No hay decisiones funcionales pendientes en el alcance actual:

- `Venta` incluye únicamente `Venta` y `Venta con cambio`;
- `Lead` y `Ayvens` quedan fuera;
- los leads válidos `Sin clasificar`, `Sin comercial elegible` y `Sin delegación
  comercial` permanecen dentro del total y se muestran como calidad del dato;
- los perfiles comerciales elegibles son `Compra/Venta` y
  `Comerciales Partner Community`.

### Reservas / Ventas

- Definir, si Dirección quiere utilizarlo, qué benchmark debe alimentar las
  conclusiones automáticas: objetivo, media ponderada, media simple o período
  anterior. Hasta entonces no se emiten recomendaciones basadas en benchmark.

### Llamadas

- Clasificar operativamente los usuarios del perfil `Pruebas comunidad
  comercial`. El perfil no se excluye automáticamente; debe revisarse con
  `reports:audit-calls-profile` y aprobar una regla explícita si procede.
- Validar con una muestra de centralita si se mantienen definitivamente los 5
  segundos descontados en llamadas directas y los 10 segundos en portales. La
  regla actual continúa marcada como provisional.

### Campañas

- Revisar y clasificar por ID las campañas que permanezcan como
  `pending_review`. El nombre puede sugerir una campaña de prueba, pero no la
  excluye de los KPIs ejecutivos sin una clasificación explícita guardada.
- Aprobar o ajustar los umbrales de muestra y coste utilizados para alertas de
  rendimiento cuando Dirección disponga de benchmarks económicos definitivos.

### Comisiones

- Validar funcionalmente las fórmulas e importes antes de aprobar cada cierre
  mensual. La aplicación ya soporta estados, snapshot, aprobación, reapertura y
  ajustes, pero un mes no se vuelve definitivo sin aprobación de Dirección o
  Administrador/IT.
- La regla de reseñas vigente se mantiene: reseñas creadas en el mes por
  `OwnerId` divididas entre las operaciones elegibles. Si Dirección quiere
  convertirla en una métrica uno-a-uno por oportunidad deberá aprobar una nueva
  fórmula; no se cambia implícitamente.

### Stock

- Confirmar mediante metadata de Salesforce el API Name exacto de la fecha o
  año de matriculación de `Product2`. Hasta entonces la matriculación no puede
  participar en el ranking de comparables.

## Decisiones cerradas e implementadas

| Informe | Decisión implantada |
|---|---|
| Leads | Normalización única de tipos, Venta sin Lead/Ayvens, calidad comercial visible, eliminados/fusionados fuera de KPIs activos y auditoría por Lead ID. |
| Reservas / Ventas | El selector de fecha define toda la cohorte; reserva/firma repetida para el mismo vehículo y fecha cuenta una vez y genera incidencia auditable. |
| Llamadas | Solo Tasks de llamada con `CallObject`; `ABANDONED` nunca es atendida ni desbordada; clasificación versionada y reproceso únicamente manual y trazable. |
| Campañas | Google/Meta, Salesforce-only, prueba, pendiente, ambiguo y sin atribuir están separados; first touch no duplica entidades; las pruebas se excluyen solo por clasificación persistida. |
| Comisiones | Mes único en seis pestañas y exports; mes actual provisional; cierre económico persistente, auditable y reproducible; ajustes posteriores no sobrescriben silenciosamente un definitivo. |
| Stock | Catálogo canónico de Salesforce, todo Disponible evaluado, 60/90 como prioridad, Top 3 teórico, plan conjunto sin sobreasignar capacidad y sin reservas logísticas persistentes. |
| Stock | Entre ventas válidas del mismo vehículo gana la firma más reciente; un empate exacto queda como `duplicate_ambiguous` y no suma. |

No deben introducirse excepciones por IDs concretos ni ajustes manuales para
cuadrar cifras. Toda nueva decisión debe centralizarse, versionarse cuando
afecte históricos y acompañarse de pruebas y auditoría por ID.
