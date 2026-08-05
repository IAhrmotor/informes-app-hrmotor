# Decisiones de negocio pendientes

Este documento separa los cambios que requieren aprobación funcional de las
correcciones técnicas ya implementadas. No deben resolverse mediante excepciones
de código ni valores deducidos de una captura.

## Stock

- Catálogo canónico y alias funcionales de combustible, modelo y versión.
- Criterio para escoger una operación cuando existen dos ventas válidas del mismo
  vehículo. Mientras no se decida, ambas quedan fuera y se auditan como duplicado.
- Confirmar si el plan conjunto debe ser una propuesta informativa o reservar
  capacidad operativa hasta su aceptación.

## Reservas / Ventas

- Benchmark de las conclusiones: objetivo, media ponderada, media simple o periodo
  anterior. Hasta entonces no se usa un benchmark para emitir recomendaciones.

## Leads

- Definición exacta de `Sin comercial elegible`.
- Confirmar si RecordType `Lead` y `Ayvens` deben formar parte del filtro Venta.
- Confirmar si `Sin clasificar` debe incluirse en el total ejecutivo; actualmente
  se incluye y debe mostrarse como calidad de dato.

## Llamadas

- Clasificación operativa del perfil `Pruebas comunidad comercial`.
- Política histórica al cambiar reglas: conservar la versión original o
  reprocesar periodos anteriores. La versión ya queda registrada en cada fila.

## Campañas

- Excluir campañas de prueba o mostrarlas en un bloque separado.
- Definir si Salesforce-only forma parte de los KPIs de rendimiento digital.
- Definir si se admiten atribuciones solapadas y cómo se comunican al usuario.

## Comisiones

- Política única de mes cerrado y tratamiento del mes en curso.
- Denominador de cumplimiento de reseñas y regla de una reseña máxima por
  oportunidad.
- Universos funcionales de Comerciales, Delegaciones, Área Manager y Financieros.
- Condiciones necesarias para marcar importes como definitivos en lugar de
  preliminares.
