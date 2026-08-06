# Informe de Stock

Actualizado: 2026-08-06.

## Fuentes y fotografía local

- Inventario: Salesforce `Product2`.
- Ventas: Salesforce `Opportunity`, congeladas en
  `salesforce_sale_snapshots`.
- Logística: `Logistica__c`.
- Capacidades: importación CSV/XLSX y `stock_delegations`.
- Histórico: `stock_daily_snapshots`.

El stock actual considera `Disponible`, `Reservado` y `Bloqueado`. Las ventas no
se reconstruyen con el Product2 actual: el snapshot conserva los valores
económicos del momento de la venta y posteriormente solo se reconcilia su
validez contra Opportunity.

La cabecera muestra período de ventas, fuente y corte de la fotografía local. La
versión de reglas no ocupa una pill visual independiente.

## Ventas válidas y duplicados

Una venta válida requiere:

- RecordType Venta o Cambio;
- contrato CV firmado;
- fecha de firma informada y dentro del período analizado;
- etapa distinta de `Cerrada Perdida`;
- vehículo de interés informado.

`Cerrada Perdida` queda fuera de ventas, rotación, rankings y recomendaciones.

Si un vehículo tiene varias ventas base-válidas:

1. se conserva la firma más reciente;
2. las anteriores quedan como `duplicate_not_selected` y guardan la Opportunity
   seleccionada;
3. si varias comparten exactamente la fecha más reciente, todas quedan como
   `duplicate_ambiguous`, no suman y requieren revisión en Salesforce;
4. `LastModifiedDate` no se usa como desempate.

Los snapshots invalidados se conservan para auditoría y no reescriben sus
importes congelados.

## Universo y antigüedad

Para contexto y capacidad se usa todo el stock Disponible, Reservado y
Bloqueado. Para proponer traslados se usan únicamente vehículos Disponibles y
operativos.

Todos los Disponibles operativos se evalúan. Los 60 y 90 días son niveles de
urgencia, no filtros de entrada:

| Prioridad | Regla principal |
|---|---|
| Normal | menos de 60 días y sin otra señal prioritaria |
| 60 días | desde 60 días |
| 90 días | desde 90 días o señales fuertes configuradas |

Los tramos del resumen son excluyentes: 0–59, 60–89, 90–119, 120–180, más de
180 y Sin fecha. Su suma debe coincidir con el stock total filtrado.

## Catálogo canónico

Salesforce es el catálogo de referencia. `stock:sync-salesforce-catalog` lee los
picklists activos de `Product2` y los guarda en `stock_catalog_values`. Los
aliases locales solo relacionan variantes históricas con un valor oficial
activo; no constituyen un segundo catálogo.

Para cada dimensión se conserva:

- valor bruto;
- valor normalizado;
- valor canónico;
- regla aplicada.

`salesforce_vehicles.catalog_normalization` mantiene esa traza. Valores no
operativos como pruebas, formación o fuera de stock se excluyen de rankings y
recomendaciones, pero siguen ocupando capacidad cuando forman parte del stock
real.

## Ranking y plan de traslados

Cada vehículo muestra:

1. destino ideal teórico;
2. segunda alternativa;
3. tercera alternativa;
4. destino ejecutable asignado por el plan conjunto, si existe.

El ranking compara primero marca, modelo y combustible; después usa
kilometraje/similitud y el score de ventas, rotación, segmento, precio y stock
existente. La matriculación todavía no participa porque no se ha confirmado su
API Name exacto en Salesforce.

Un destino teórico puede estar completo. En ese caso se muestra como no
ejecutable, con exceso previsto y plazas que habría que liberar. El plan conjunto
consume capacidad virtualmente y nunca asigna más vehículos que plazas libres.
No crea reservas persistentes, órdenes logísticas ni cadenas automáticas entre
tiendas.

La conciliación visual separa:

- contexto: total, disponibles, reservados y bloqueados;
- evaluación: disponibles evaluados, catálogo no operativo y sin alternativas;
- plan: asignados y sin asignar por capacidad;
- urgencia: normal, 60 días y 90 días.

## Capacidad, ratios y calidad

- Capacidad libre = plazas totales − stock total.
- Ocupación = stock total / plazas totales.
- Si una delegación tiene stock cero, el ratio ventas/stock es no disponible; no
  se sustituye por el número de ventas.
- Las alertas visuales usan todo el stock, no la muestra filtrada.
- El nombre de delegación aplica un filtro común a stock, ventas y rankings.

Calidad del dato incluye, entre otros, entradas/delegaciones/catálogos ausentes,
entregados que continúan en stock, firmas inconsistentes, `Cerrada Perdida`,
duplicados de venta, entradas futuras, tiendas sin capacidad, variantes de
catálogo y valores no operativos.

Exportación: `/informes/stock/exportar/calidad-dato.xlsx`.

## Rendimiento

- El universo se calcula antes de paginar.
- Recomendaciones materializa perfiles compactos globales y desarrolla motivos
  completos solo para la página visible.
- La pestaña Capacidades no construye el dataset analítico.
- Calidad se calcula únicamente cuando la sección lo necesita.
- Las consultas de ventas seleccionan columnas concretas y evitan hidratación
  innecesaria.

## Operación

```bash
php artisan stock:sync-salesforce-catalog
php artisan stock:sync-daily --sales-days=180 --logistics-days=365
```

Scheduler vigente: 03:30 `Europe/Madrid`, con
`stock:sync-daily --sales-days=14 --logistics-days=30` y bloqueo de solapamiento
durante 120 minutos.

Archivos principales:

- `app/Services/Reports/Stock/StockDashboardDatasetService.php`;
- `app/Services/Reports/Stock/StockRecommendationService.php`;
- `app/Services/Reports/Stock/StockSaleValidityService.php`;
- `app/Services/Reports/Stock/StockCatalogNormalizer.php`;
- `app/Services/Reports/Stock/SalesforceStockCatalogSyncService.php`.
