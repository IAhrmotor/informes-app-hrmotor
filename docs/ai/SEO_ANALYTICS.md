# SEO/Analytics

## Objetivo y alcance actual

Este documento es el contrato operativo del informe SEO/Analytics. El Lote 1
solo configura y diagnostica accesos read-only. No ingiere métricas, no crea
snapshots, no calcula KPI y no genera alertas.

`GET /informes/seo-analytics` solo lee configuración Laravel y nunca llama a
proveedores. La verificación externa se ejecuta manualmente con:

```bash
php artisan seo:diagnose-integrations --live
```

Sin `--live`, el comando tampoco realiza tráfico de red ni muestra secretos.

## Fuentes y estado verificable

| Fuente | Configuración independiente | Verificación del Lote 1 |
|---|---|---|
| Google Search Console | OAuth propio + property | Listado read-only de sites y coincidencia exacta |
| Salesforce Leads | Configuración Salesforce existente | Verificado: `Medio_origen__c` mediante `Lead describe` |
| Google Analytics 4 | OAuth propio + property ID | Property, metadata, timezone y Key Events read-only |
| SISTRIX | API key propia | Endpoint `credits`; AI Check no se ejecuta |

Los candidatos conocidos `sc-domain:hrmotor.com` y `313695489` no están
verificados por el repositorio. Solo la salida live puede acreditar acceso. Una
configuración completa significa “pendiente de validar”, nunca “conectada”. En
la verificación local del 2026-08-17 Search Console, GA4 y SISTRIX no estaban
configurados, por lo que permanecen pendientes.

## Salesforce y Lead orgánico

Contrato de negocio aprobado:

- Unidad: registro Lead de Salesforce, no persona.
- No se deduplican personas; varios Leads de una persona cuentan por separado.
- Condición futura: `Medio_origen__c` (`Medio de origen`, tipo `picklist`)
  contiene exactamente el valor `Orgánico`.
- Candidatos iniciales: `LEA_SEL_Medio_Origen__c` y `Medio_Nuevo__c`, además de
  campos cuyo label del describe identifique inequívocamente “Medio”.
- El describe read-only del 2026-08-17 verificó un único candidato con ese valor:
  `Medio_origen__c`. `LEA_SEL_Medio_Origen__c` contiene `Organic` en inglés y no
  satisface la igualdad funcional exacta. La sincronización no se modifica en
  este lote.
- En futuras verificaciones, el campo no se considerará inequívoco si `Orgánico`
  no existe o si más de un candidato lo contiene.

Salesforce Lead orgánico y GA4 Key Event son cardinalidades independientes. No
se suman ni se presentan como una única métrica.

## Tráfico de marca y país

- País principal Search Console: `ESP` (ISO 3166-1 alpha-3).
- Timezone de aplicación: `Europe/Madrid`.
- Variantes iniciales: `hr motor`, `hrmotor`, `hr-motor`, `hrmotor.com`.
- `SEO_BRAND_VARIANTS` permite una lista separada por comas; se aplica trim, se
  eliminan vacíos y duplicados sin distinguir mayúsculas, y se rechaza texto
  inválido o con caracteres de control.

El Lote 1 no clasifica branded/non-branded ni inventa errores ortográficos,
fabricantes, competidores o variantes urbanas.

## Páginas importantes

Son candidatas estratégicas: Home; compraventa/venta; tasación; financiación;
delegaciones y concesionarios; listado principal de stock; captación y
formularios; landings de campañas activas; y páginas de marcas, modelos,
provincias o filtros con tráfico orgánico relevante.

Una página puede promocionarse por volumen histórico. Las fichas individuales
de vehículos se controlarán principalmente como conjunto/plantilla y solo se
evaluarán individualmente con tráfico histórico material. No se definen URLs,
slugs o regex hasta observar sitemap, Search Console, web real y stock.

## SISTRIX

La ausencia de `SISTRIX_API_KEY` es un estado válido: “Pendiente de conectar”.
El diagnóstico usa únicamente el endpoint básico `credits`, enviando la key en
body y sin imprimir saldo. Una respuesta válida acredita API básica, no acceso a
AI Check. Ningún `ai.check.*` se ejecuta en este lote.

## Timezone, cierre y completitud

Cada fuente futura conservará su timezone y corte propio. La timezone GA4 se
obtiene de la property; Search Console y los demás cierres deberán documentarse
al implementar su ingesta. No se debe convertir una fecha abierta o incompleta
en cero, ni mezclarla con una fecha cerrada.

## Contrato de persistencia futuro

Los futuros registros de fuente deberán poder representar, como mínimo:

- `source`;
- `date` o `data_date`;
- timestamp de extracción;
- timezone de fuente;
- indicador `complete`/`incomplete`;
- `closed-through`;
- dimensiones;
- métricas;
- identificadores propios de la fuente.

Este contrato no crea columnas ni tablas y no sustituye la fuente original.

## Lotes posteriores

El Lote 2 podrá definir ingesta, persistencia y clasificación branded cuando
properties, campos y dimensiones estén verificados. Permanecen fuera: métricas
reales, Search Analytics, GA4 diario, SISTRIX AI Check, crawler, snapshots,
motor comparativo, desviaciones, alertas y correo diario.

## Seguridad

- Credenciales únicamente en entorno/configuración; nunca BD, frontend o docs.
- Search Console requiere `webmasters.readonly`; GA4,
  `analytics.readonly`.
- Diagnóstico CLI read-only, con timeouts y errores sanitizados.
- No existen endpoints web de diagnóstico ni llamadas externas en render.
- No mostrar ceros, events, properties o estados verificados sin evidencia.
