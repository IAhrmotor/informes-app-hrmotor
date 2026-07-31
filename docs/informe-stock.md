# Informe de Stock

## Tramos de antigüedad

La antigüedad del stock se calcula como fecha actual menos fecha de entrada. Los
tramos son mutuamente excluyentes:

| Tramo | Regla |
|---|---|
| Menos de 60 días | `días < 60` |
| 60–89 días | `60 <= días < 90` |
| 90–119 días | `90 <= días < 120` |
| 120–180 días | `120 <= días <= 180` |
| Más de 180 días | `días > 180` |
| Sin fecha de entrada | No se puede calcular la antigüedad |

La suma de los cinco tramos temporales y `Sin fecha de entrada` debe coincidir
con el stock total filtrado. Los vehículos sin fecha siguen apareciendo además
en Calidad del dato.

Los umbrales operativos de las recomendaciones se mantienen configurables en
`config/stock.php`: revisión desde 60 días y prioridad desde 90 días.

## Resumen

- El KPI `Ventas por stock` no se muestra.
- La evolución diaria representa tres series lineales: Disponible, Reservado y
  Bloqueado, usando las fotografías diarias del periodo.
- Las alertas visuales de capacidad muestran delegaciones por encima del 100% o
  por debajo del 80% de ocupación. Se calculan con todo el stock actual para que
  los filtros del informe no generen falsas alertas.
- Calidad del dato conserva sus controles actuales.

## Delegaciones y ventas

Delegaciones, Ventas y Rankings se presentan en una sola pestaña. El nombre de
cada delegación es un enlace que aplica ese filtro al stock, a las ventas y a
todos los perfiles comerciales.

Los rankings se ordenan exclusivamente por ventas y ofrecen tres vistas:

- más vendidos (10 primeros);
- menos vendidos (10 últimos);
- todos los valores.

Stock actual, rotación, antigüedad y ventas por stock siguen visibles como datos
de contexto. Las operaciones individuales vendidas no se muestran en esta vista.

## Recomendaciones

El selector Modelo del simulador depende de la Marca seleccionada. La tabla de
candidatos se denomina `Vehículos propuestos para traslado`; sus reglas de
cálculo y paginación no cambian.

## Rendimiento de carga

- Capacidades no construye el dataset de stock y ventas.
- Calidad del dato se calcula solo en Resumen y agrupa los recuentos básicos en
  consultas condicionales.
- Resumen y Delegaciones cargan únicamente los campos de venta necesarios y sin
  hidratación Eloquent completa.
- Recomendaciones evalúa todos los candidatos con perfiles compactos antes de
  paginar; los motivos completos se materializan para la página visible.
