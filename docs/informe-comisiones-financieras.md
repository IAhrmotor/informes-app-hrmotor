# Guía de revisión de comisiones financieras

Actualizado: 2026-08-24  
Fuente de verdad: implementación y pruebas vigentes del proyecto.

## 1. Objetivo de esta guía

Esta guía permite presentar y revisar la pestaña **Financieros** del informe de
comisiones sin tener que conocer el código. Explica:

- qué operaciones entran;
- de dónde procede cada dato;
- cómo se asigna una zona o una excepción personal;
- cómo se calculan los tres bloques y la comisión final;
- qué representa cada tarjeta y columna del front;
- cómo reproducir el resultado con Salesforce;
- qué comprobaciones hacer antes de aprobar una cifra.

La pantalla se encuentra en:

```text
/informes/comisiones-comerciales?tab=financials&month=YYYY-MM
```

El rol `Financiero` aterriza directamente en esta pestaña. Dirección,
Administrador/IT y Auditor de comisiones también pueden consultarla. Los controles
de acceso se aplican en servidor; no dependen únicamente de que el enlace esté
visible.

## 2. Resumen para una presentación

El cálculo se puede explicar en cinco pasos:

1. Se toman las `Opportunity` cuya **fecha de firma** está dentro del mes.
2. Se conservan ventas y cambios no cerrados como perdidos y con zona financiera
   válida.
3. Se agrupan por zona financiera, salvo las excepciones personales configuradas,
   que se separan por Salesforce User ID.
4. Se calculan tres incentivos: financiación, rentabilidad financiera válida y
   garantías.
5. La comisión final es la suma de los tres bloques, excepto en una regla personal,
   que sustituye completamente esa suma.

```text
Opportunity de Salesforce
        ↓ sincronización
salesforce_opportunities
        ↓ filtros de mes, etapa, tipo y zona
operaciones elegibles
        ↓ agrupación por zona o excepción personal
Bloque 1 + Bloque 2 + Bloque 3
        ↓
comisión final mostrada
```

Dos ideas evitan la mayoría de errores de conciliación:

- el bloque 2 no usa `Beneficio_financiacion_comercial__c`; usa la comisión
  financiera válida menos su descuento financiero;
- un interés vacío o excluido retira la operación **solo del bloque 2**. La misma
  operación sigue participando en los bloques 1 y 3.

## 3. Fuente de los datos

### 3.1 Origen y persistencia

La fuente externa es el objeto `Opportunity` de Salesforce. La pantalla no consulta
Salesforce en tiempo real: el comando de sincronización copia los campos a
`salesforce_opportunities` y el informe calcula sobre esa réplica local.

```bash
php artisan salesforce:sync-opportunities --from=2026-07-01 --to=2026-08-01
```

El límite `--from` es inclusivo y `--to` es exclusivo. El sincronizador trae una
oportunidad si su creación, reserva o firma entra en el rango; después la pestaña
Financieros vuelve a filtrar exclusivamente por la fecha de firma.

Para ver la SOQL construida por el sincronizador sin cambiar la lógica:

```bash
php artisan salesforce:sync-opportunities --from=2026-07-01 --to=2026-08-01 --debug-soql
```

Este comando realiza una sincronización real. No debe usarse `--fresh` para una
revisión ordinaria porque vacía previamente la tabla local de oportunidades.

### 3.2 Diccionario de campos

| Concepto del informe | Campo Salesforce | Columna local | Uso |
|---|---|---|---|
| Opportunity ID | `Id` | `salesforce_id` | Trazabilidad y detalle |
| Opportunity | `Name` | `name` | Etiqueta visible |
| Etapa | `StageName` | `stage_name` | Excluir `Cerrada Perdida` |
| Tipo estándar | `RecordType.Name` | `record_type_name` | Fallback de Venta/Cambio |
| Tipo fórmula | `Tipo_de_registro_oportunidad__c` | `opportunity_record_type_formula` | Filtro principal Venta/Cambio |
| Fecha de firma | `Fecha_firma_contrato__c` | `cv_signed_date` | Mes del informe |
| Propietario | `OwnerId` | `owner_id` | Aplicar excepciones personales |
| Delegación del propietario | `Owner.USR_SEL_Delegacion__c` | `owner_delegation` | Fallback de zona |
| Zona financiera | `zona_financiera__c` | `financial_zone` | Agrupación principal |
| Importe total | `OPO_FOR_Importe_total__c` | `opo_for_importe_total` | Denominador del % financiado |
| Importe financiado | `Importe_financiado__c` | `importe_financiado` | Financiación y denominadores |
| Comisión financiera | `Comisi_n_Financiera__c` | `financial_commission` | Comisión neta y beneficio válido |
| Descuento financiero | `OPO_DIV_Descuento_financiera__c` | `financial_discount` | Resta a la comisión financiera |
| Tipo de interés | `Inter_s_elegido__c` | `interest_rate` | Elegibilidad exclusiva del bloque 2 |
| Garantía premium | `Garant_a_Total__c` | `garantia_total` | Base del bloque 3 |

`OPO_CAS_Contrato_CV_firmado__c` también se sincroniza como `cv_signed`, pero la
implementación vigente de Financieros no lo exige: la inclusión depende de que
`Fecha_firma_contrato__c` tenga una fecha dentro del mes.

`Beneficio_financiacion_comercial__c` se sincroniza y está disponible localmente,
pero **no interviene en ninguno de los tres bloques de Financieros**.

Los importes nulos se convierten en cero al calcular. Cada importe por oportunidad
se redondea primero a dos decimales; las sumas y resultados de bloque vuelven a
redondearse a dos decimales. Los ratios también se muestran con dos decimales.

## 4. Universo de operaciones

Una oportunidad entra en el cálculo cuando cumple todas estas condiciones:

1. `Fecha_firma_contrato__c >= primer día del mes`.
2. `Fecha_firma_contrato__c < primer día del mes siguiente`.
3. `StageName` no es `Cerrada Perdida`, sin distinguir mayúsculas.
4. `Tipo_de_registro_oportunidad__c` es `Venta` o `Cambio`, sin distinguir
   mayúsculas.
5. Solo si el tipo fórmula es `NULL`, se acepta como fallback
   `RecordType.Name = Venta` o `Cambio`.
6. La zona resuelta no es `General` ni `Sin Zona`.

No se filtra por propietario activo, por `Gestion_de_venta__c`, por financiación
pagada ni por el booleano de contrato firmado. Tampoco se incluyen Tasación,
Facilitea u otros tipos.

### 4.1 SOQL de contraste

La siguiente consulta aproxima exactamente el universo funcional. Las fechas son
un ejemplo para julio de 2026 y deben sustituirse por el mes revisado.

```sql
SELECT
    Id,
    Name,
    StageName,
    RecordType.Name,
    OwnerId,
    Owner.Name,
    Owner.USR_SEL_Delegacion__c,
    OPO_CAS_Contrato_CV_firmado__c,
    Fecha_firma_contrato__c,
    Tipo_de_registro_oportunidad__c,
    zona_financiera__c,
    OPO_FOR_Importe_total__c,
    Importe_financiado__c,
    Comisi_n_Financiera__c,
    OPO_DIV_Descuento_financiera__c,
    Inter_s_elegido__c,
    Garant_a_Total__c,
    Beneficio_financiacion_comercial__c
FROM Opportunity
WHERE IsDeleted = FALSE
  AND Fecha_firma_contrato__c >= 2026-07-01
  AND Fecha_firma_contrato__c < 2026-08-01
  AND StageName != 'Cerrada Perdida'
  AND (
      Tipo_de_registro_oportunidad__c IN ('Venta', 'Cambio')
      OR (
          Tipo_de_registro_oportunidad__c = NULL
          AND RecordType.Name IN ('Venta', 'Cambio')
      )
  )
ORDER BY zona_financiera__c, OwnerId, Name, Id
```

Salesforce puede devolver etiquetas con distinta capitalización. El servicio local
compara etapa y tipos sin distinguir mayúsculas; al contrastar manualmente debe
aplicarse el mismo criterio.

La exclusión de `General`/`Sin Zona` y el fallback por delegación se hacen en la
aplicación, por lo que deben aplicarse después de exportar la query.

## 5. Cómo se resuelve la zona

La prioridad es:

1. `zona_financiera__c`, si contiene un valor no vacío;
2. si no existe, mapeo de `Owner.USR_SEL_Delegacion__c`;
3. si tampoco se puede resolver, `Sin Zona`, que queda fuera del informe.

Las etiquetas `Zona Cristina`, `Zona Nuria`, `Zona Carlos` y `Zona Irene` se
normalizan sin distinguir mayúsculas. `General`, vacío y `Sin Zona` se excluyen.

Fallback vigente por delegación:

| Zona resultante | Delegaciones |
|---|---|
| Zona Cristina | Bilbao, Fontellas, Gijón, Pamplona, San Sebastián, Zaragoza, A Coruña, Valladolid, Badalona, Manresa, Girona, Lleida, Sant Boi, Lliçà de Valls, Barcelona, Elche, Alcoy, Villareal |
| Zona Nuria | Sedaví, Castellón |
| Zona Carlos | Alcalá de Guadaíra, Badajoz, Málaga, Málaga Centro, Palma, Sevilla, Torrejón de Ardoz, Rivas, Call Rivas, Alcobendas, Collado Villalba, Valencia, Murcia, Dos Hermanas |
| Zona Irene | Alicante, Paterna |

La delegación se normaliza antes del mapeo para tolerar tildes, mayúsculas y
separadores. Un valor financiero explícito desconocido no se remapea: se conserva
como su propia zona.

## 6. Configuración mensual

Los tramos se leen de `commercial_commission_month_settings.settings` para el mes
seleccionado. Si no hay configuración guardada, se usan los valores por defecto de
`CommercialCommissionFormulaConfigService`.

Los umbrales se normalizan en orden descendente y se aplica el primer tramo cuyo
mínimo se cumple. Administrador/IT puede gestionar la configuración en:

```text
/informes/configuracion-comisiones?month=YYYY-MM
```

El mes actual es editable. Un mes anterior requiere apertura temporal y vuelve a
cerrarse al guardar. Para una auditoría histórica siempre se deben leer los tramos
efectivos de ese mes; no se debe asumir que coinciden con los defaults siguientes.

### 6.1 Tramos por defecto del bloque 1

| % financiado de la zona | Incentivo aplicado a comisión neta |
|---:|---:|
| Mayor que 47,00 % (`>= 47,0001`) | 1,25 % |
| 44,10 % a 47,00 % | 1,00 % |
| 42,10 % a menos de 44,10 % | 0,75 % |
| 40,10 % a menos de 42,10 % | 0,50 % |
| 39,10 % a menos de 40,10 % | 0,40 % |
| 38,00 % a menos de 39,10 % | 0,20 % |
| Menos de 38,00 % | 0,10 % |

### 6.2 Tramos por defecto del bloque 2

| Rentabilidad financiera válida | Incentivo aplicado al beneficio válido |
|---:|---:|
| 16,60 % o más | 0,75 % |
| 15,60 % a menos de 16,60 % | 0,50 % |
| 14,50 % a menos de 15,60 % | 0,40 % |
| 14,00 % a menos de 14,50 % | 0,20 % |
| 13,00 % a menos de 14,00 % | 0,10 % |
| Menos de 13,00 % | 0,00 % |

### 6.3 Tramos por defecto del bloque 3

| % de garantías | Incentivo aplicado a garantía premium |
|---:|---:|
| 7,00 % o más | 0,50 % |
| 6,00 % a menos de 7,00 % | 0,30 % |
| 5,00 % a menos de 6,00 % | 0,20 % |
| Menos de 5,00 % | 0,00 % |

### 6.4 Intereses excluidos por defecto

`3,99 %`, `4,99 %` y `5,99 %` quedan fuera del bloque 2. Para comparar, el
sistema elimina `%`, convierte coma en punto y recorta espacios. Por ejemplo,
`4,99%` y `4.99` coinciden.

## 7. Cálculos implementados

Todas las fórmulas se calculan sobre el conjunto agrupado por zona, salvo una
regla personal activa, que se agrupa por `OwnerId`.

### 7.1 Comisión neta

```text
comisión financiera total = suma(Comisi_n_Financiera__c)
descuento financiero total = suma(OPO_DIV_Descuento_financiera__c)
comisión neta = comisión financiera total - descuento financiero total
```

### 7.2 Bloque 1: porcentaje financiado

Todas las operaciones elegibles de la zona participan, aunque no tengan interés o
su interés esté excluido del bloque 2.

```text
% financiado = suma(Importe_financiado__c)
               / suma(OPO_FOR_Importe_total__c) × 100

comisión bloque 1 = comisión neta × incentivo del tramo de % financiado
```

Si el importe total es cero o negativo, el porcentaje es `0 %`.

### 7.3 Bloque 2: rentabilidad financiera válida

Primero se crea un subconjunto de operaciones válidas. Una operación es válida
solo cuando `Inter_s_elegido__c` está informado y su valor normalizado no aparece
en la lista mensual de intereses excluidos.

```text
beneficio financiero válido =
    suma(Comisi_n_Financiera__c de operaciones válidas)
  - suma(OPO_DIV_Descuento_financiera__c de operaciones válidas)

importe financiado válido =
    suma(Importe_financiado__c de operaciones válidas)

rentabilidad = beneficio financiero válido
               / importe financiado válido × 100

comisión bloque 2 = beneficio financiero válido
                    × incentivo del tramo de rentabilidad
```

Si el importe financiado válido es cero o negativo, la rentabilidad es `0 %`.
El campo Salesforce `Beneficio_financiacion_comercial__c` no se usa en esta
fórmula.

### 7.4 Bloque 3: garantías

Todas las operaciones elegibles de la zona participan.

```text
garantía premium = suma(Garant_a_Total__c)

% garantías = garantía premium
              / suma(Importe_financiado__c) × 100

comisión bloque 3 = garantía premium
                    × incentivo del tramo de % garantías
```

Si el importe financiado es cero o negativo, el porcentaje es `0 %`.

### 7.5 Comisión final ordinaria

```text
comisión final = comisión bloque 1
               + comisión bloque 2
               + comisión bloque 3
```

### 7.6 Excepciones personales

Desde junio de 2026 existen dos reglas personales configuradas por Salesforce
User ID. La etiqueta visible es informativa: ni nombre, zona ni email seleccionan
la regla.

```text
comisión personal = comisión neta × 0,50 %
comisión final = comisión personal
```

Cuando se aplica, los bloques 1, 2 y 3 se muestran a cero porque la regla personal
los **sustituye**; no se suma a ellos. Antes de junio de 2026 no se aplica.

## 8. Qué significa cada elemento del front

### 8.1 Tarjetas superiores

| Tarjeta | Significado real |
|---|---|
| Zonas financieras | Número de filas agregadas. Normalmente son zonas, pero una excepción personal genera una fila propia; por eso no siempre equivale al número de zonas distintas. |
| Operaciones elegibles | Oportunidades que superan mes, etapa, tipo y zona. |
| Rentabilidad válida | Operaciones elegibles que además tienen interés informado y no excluido; son las que forman el bloque 2. |
| Total comisión | Suma de `comisión final` de todas las filas agregadas. |

La tarjeta `Excluidas bloque 2` cuenta operaciones sin interés o con interés
excluido. No implica que estén excluidas de la comisión completa.

### 8.2 Tabla de resumen

| Columna | Cómo leerla |
|---|---|
| Responsable/Zona financiera | Zona agregada o etiqueta de la excepción personal. |
| Total comisión | Resultado final ordinario o personal. |
| Ops. | Número de oportunidades del grupo. |
| Imp. total | Suma de `OPO_FOR_Importe_total__c`. |
| Imp. financiado | Suma de `Importe_financiado__c` de todas las operaciones. |
| % financiado | Importe financiado / importe total. |
| Com. financiera | Suma de `Comisi_n_Financiera__c`. |
| Desc. financiera | Suma de `OPO_DIV_Descuento_financiera__c`. |
| Com. neta | Comisión financiera menos descuento financiero. |
| Inc. bloque 1 | Porcentaje del tramo alcanzado. |
| Com. bloque 1 | Comisión neta por incentivo del bloque 1. |
| Rentabilidad | Beneficio válido / importe financiado válido. |
| Inc. bloque 2 | Porcentaje del tramo de rentabilidad alcanzado. |
| Com. bloque 2 | Beneficio válido por incentivo del bloque 2. |
| Garantía premium | Suma de `Garant_a_Total__c`. |
| % garantías | Garantía premium / importe financiado total. |
| Inc. bloque 3 | Porcentaje del tramo de garantía alcanzado. |
| Com. bloque 3 | Garantía premium por incentivo del bloque 3. |
| Regla especial | Porcentaje personal y resultado, o `-` si no aplica. |

La columna `Rentabilidad` no permite por sí sola reconstruir el numerador y
denominador porque la tabla no muestra `beneficio financiero válido` ni `importe
financiado válido` como columnas separadas. Para esa comprobación debe usarse el
detalle de oportunidades.

### 8.3 Detalle auditable de rentabilidad

El detalle muestra una fila por oportunidad elegible con:

- zona;
- Opportunity ID y nombre;
- tipo de interés;
- motivo de inclusión o exclusión del bloque 2;
- importe financiado;
- comisión financiera;
- descuento financiero.

Los estados posibles son:

- `Incluida`;
- `Tipo de interes vacio`;
- `Tipo de interes excluido`.

Este detalle está orientado al bloque 2. No muestra el importe total ni la garantía
por oportunidad, aunque ambos sí forman parte de los cálculos agregados.

### 8.4 Exportación XLSX

La exportación de un usuario con rol `Financiero` genera una hoja `Financieros`
con dos columnas: `Responsable/Zona financiera` y `Comision final`. El resultado
usa el mismo servicio que el dashboard, pero no incluye el desglose de fórmulas ni
el detalle por oportunidad.

## 9. Ejemplo completo

Ejemplo ilustrativo de una zona, suponiendo que todos los intereses del subconjunto
indicado son válidos:

| Dato agregado | Valor |
|---|---:|
| Importe total | 200.000,00 € |
| Importe financiado total | 90.000,00 € |
| Comisión financiera total | 12.000,00 € |
| Descuento financiero total | 2.000,00 € |
| Garantía premium | 5.400,00 € |
| Importe financiado válido para bloque 2 | 70.000,00 € |
| Beneficio financiero válido | 10.500,00 € |

Cálculo:

```text
comisión neta = 12.000 - 2.000 = 10.000 €

% financiado = 90.000 / 200.000 × 100 = 45,00 %
incentivo bloque 1 = 1,00 %
comisión bloque 1 = 10.000 × 1,00 % = 100,00 €

rentabilidad = 10.500 / 70.000 × 100 = 15,00 %
incentivo bloque 2 = 0,40 %
comisión bloque 2 = 10.500 × 0,40 % = 42,00 €

% garantías = 5.400 / 90.000 × 100 = 6,00 %
incentivo bloque 3 = 0,30 %
comisión bloque 3 = 5.400 × 0,30 % = 16,20 €

comisión final = 100,00 + 42,00 + 16,20 = 158,20 €
```

## 10. Procedimiento de revisión recomendado

### Paso 1. Fijar el período

Confirmar el `month=YYYY-MM` de la URL. El intervalo siempre es `[primer día,
primer día del mes siguiente)`.

### Paso 2. Confirmar la configuración efectiva

Revisar en Configuración de comisiones los tres juegos de tramos y la lista de
intereses excluidos para ese mes.

### Paso 3. Extraer Salesforce

Ejecutar la SOQL de contraste y exportar al menos los campos del diccionario. No
usar la query genérica de Comerciales porque su universo exige otros filtros.

### Paso 4. Reconciliar el universo

Comprobar, en este orden:

1. fecha de firma;
2. etapa;
3. tipo fórmula y fallback de Record Type;
4. zona explícita o fallback por delegación;
5. exclusión de General/Sin Zona;
6. agrupación personal por `OwnerId`, si corresponde.

### Paso 5. Recalcular importes

Comparar primero cantidades y sumas de campos. Después calcular porcentajes y
aplicar los tramos. Mantener separados:

- conjunto total de la zona para bloques 1 y 3;
- subconjunto de interés válido para bloque 2.

### Paso 6. Revisar el detalle

Cada diferencia del bloque 2 debe poder explicarse con el Opportunity ID, el
interés sincronizado y su estado. Para bloques 1 y 3 se necesita complementar el
detalle del front con la exportación de Salesforce, porque sus bases unitarias no
se muestran completas.

### Paso 7. Documentar el corte

Anotar fecha/hora de sincronización, mes, configuración efectiva y cantidad de
operaciones. Financieros no dispone actualmente de snapshot económico definitivo,
por lo que una resincronización posterior puede cambiar un mes histórico.

## 11. Preguntas frecuentes

### ¿Por qué una operación aparece pero no suma rentabilidad?

Porque tiene `Inter_s_elegido__c` vacío o uno de los tipos excluidos. Sigue
afectando al importe financiado, comisión neta y garantías de los bloques 1 y 3.

### ¿Por qué el beneficio no coincide con Beneficio financiación comercial?

Porque Financieros no usa ese campo. El beneficio válido es comisión financiera
menos descuento financiero, limitado a operaciones con interés válido.

### ¿Por qué una operación está en una zona distinta a la delegación?

Porque `zona_financiera__c` tiene prioridad. La delegación solo es fallback cuando
el campo financiero está vacío.

### ¿Por qué los tres bloques están a cero y hay total?

Porque se ha aplicado una regla personal que calcula el 0,50 % de la comisión neta
y sustituye los tres bloques.

### ¿Por qué una cifra histórica cambió?

La pestaña se recalcula sobre los datos locales actuales y su configuración
mensual; no participa en los snapshots definitivos de Comerciales, Delegaciones o
Área Manager.

### ¿Por qué el bloque 2 completo está a cero?

Puede deberse a que todas las operaciones carecen de interés, todas usan intereses
excluidos, el importe financiado válido es cero o la rentabilidad cae por debajo
del 13 %. La pantalla emite un aviso específico si todas quedan fuera y hay
intereses vacíos.

## 12. Consideraciones de seguridad, rendimiento y auditoría

- El front muestra nombres e IDs de oportunidades. Solo debe darse acceso a roles
  con necesidad operativa y no se deben copiar esos datos a documentación pública.
- El rol `Financiero` ve el conjunto completo de zonas financieras; actualmente no
  existe un ámbito por zona o por `OwnerId`. Conviene validar que este alcance cumple
  el principio de mínimo privilegio.
- Financieros es operativo/provisional: no tiene cierre económico ni snapshot
  definitivo propio. Para una aprobación formal debe conservarse externamente el
  corte de datos y la configuración revisada.
- La tarjeta `Zonas financieras` cuenta filas agregadas, no zonas distintas. Las
  excepciones personales pueden hacer que el número sea mayor.
- Las operaciones descartadas por `Sin Zona`/`General` no aparecen en el detalle
  visible. Deben controlarse desde Salesforce para detectar problemas de calidad.
- El cálculo carga en memoria todas las oportunidades elegibles del mes y el front
  representa todo el detalle sin paginación. Un volumen mensual muy alto puede
  aumentar memoria, tiempo de respuesta y exposición de datos.
- La consulta local usa un índice simple de `cv_signed_date`, aunque `whereDate`
  puede limitar su aprovechamiento según el motor de base de datos. Debe vigilarse
  el plan de ejecución si crece el volumen.

## 13. Archivos que definen el comportamiento

- `app/Services/Reports/FinancialCommissions/FinancialCommissionDashboardService.php`
- `app/Services/Reports/CommercialCommissions/CommercialCommissionFormulaConfigService.php`
- `app/Services/Reports/CommercialCommissions/CommissionMonthResolver.php`
- `app/Services/Reports/ReservationsSales/Sync/SalesforceOpportunitySyncService.php`
- `app/Http/Controllers/Reports/CommercialCommissions/CommercialCommissionDashboardController.php`
- `resources/views/reports/commercial-commissions/partials/financial-tab.blade.php`
- `resources/views/reports/commercial-commissions/settings.blade.php`
- `config/commercial_commissions.php`
- `tests/Feature/CommercialCommissionDashboardTest.php`

## 14. Checklist breve para aprobar una comisión

- [ ] El mes de la URL es el correcto.
- [ ] La réplica de Opportunity está sincronizada para todo el rango de firma.
- [ ] Cantidad de operaciones conciliada con Salesforce.
- [ ] Venta/Cambio, etapa y zona revisados.
- [ ] Tramos mensuales e intereses excluidos confirmados.
- [ ] Comisión y descuento financiero conciliados.
- [ ] Bloque 2 recalculado solo con intereses válidos.
- [ ] Garantías e importe financiado conciliados para bloque 3.
- [ ] Excepciones personales verificadas por Salesforce User ID.
- [ ] Fecha/hora del corte y evidencias de revisión conservadas.
