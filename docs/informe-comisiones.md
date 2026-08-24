# Informe de Comisiones

Actualizado: 2026-08-24.

La guía completa, orientada a presentar y auditar la pestaña **Financieros**, se
encuentra en `docs/informe-comisiones-financieras.md`. Incluye campos Salesforce,
SOQL de contraste, universo, tramos, fórmulas, lectura de cada columna, ejemplo y
checklist de aprobación.

## Mes compartido

`CommissionMonthResolver` fija un único mes para:

- Comerciales;
- Delegaciones;
- Call Center;
- Contact Center;
- Área Manager;
- Financieros;
- cabecera, textos, detalles y exportaciones.

El mes solicitado se conserva en URL y selector. El mes actual puede consultarse
y siempre se muestra como `Provisional`; ninguna pestaña cambia por su cuenta al
último mes cerrado.

## API mensual de Comerciales

`GET /api/comisiones_comercial` exige `salesforce_id` y acepta opcionalmente
`month=YYYY-MM`. La identidad se compara de forma exacta y exclusiva con el
Salesforce User ID, incluida su capitalización; no existe fallback por nombre,
email ni coincidencia parcial.

Con `month`, la respuesta contiene `commercial_id`, `month`, `month_label`,
`economic_status`, `has_data` y `row`. `row` es, sin mapeo ni redondeo adicional,
la fila completa de `summary_rows` de la pestaña Comerciales e incluye `details`.
`200`, `has_data=false` y `row=null` significa exclusivamente que el usuario
actual existe, está activo, es elegible y no tiene fila real en ese período. Un
ID inexistente, técnico, inactivo o no elegible recibe `404` genérico cuando no
hay una fila histórica canónica. Formatos, meses imposibles y meses futuros
reciben `422` y nunca se sustituyen por el mes actual.

Si el dataset vivo devuelve `ready=false`, la API responde `503` con un mensaje
genérico; no expone las incidencias internas ni convierte la indisponibilidad en
`has_data=false` o comisión cero. Sin `month`, la indisponibilidad del mes actual
o del bloque legacy del mes anterior invalida de forma segura toda la respuesta.

Sin `month`, `CommissionMonthResolver` selecciona el mismo mes que el informe. Se
añade la fila canónica mensual y se conservan temporalmente `current_month` y
`previous_closed_month` con su forma legacy para no romper un consumidor externo
que no puede identificarse en este repositorio.

La API consulta `CommercialCommissionClosureService::definitiveSnapshot()` para
el scope `commercials`. Si el bloque es definitivo, selecciona la fila del
snapshot congelado que usa la pantalla antes de consultar la elegibilidad viva.
Una fila definitiva sigue siendo válida aunque el usuario actual esté inactivo,
haya cambiado de perfil o ya no exista localmente. Los meses provisionales,
pendientes o reabiertos usan una única construcción viva
`CommercialCommissionDashboardService::build($month, true, false, true)`: no se
construyen Delegaciones ni otras pestañas y no existe una segunda fórmula.

## Cierre económico por bloque

Estados persistentes:

- `provisional`;
- `pending_approval`;
- `definitive`;
- `reopened`.

Solo pueden cerrarse `Comerciales`, `Delegaciones` y `Área Manager`. Cada cierre
tiene estado, aprobación, reapertura, eventos y versión de snapshot propios por
combinación `month + closure_scope`. Call Center, Contact Center y Financieros
siguen siendo operativos/provisionales y no se incluyen como requisito ni como
payload de los cierres económicos.

Un bloque solo puede pasar a definitivo cuando ha terminado el mes natural, están confirmados sus componentes y aprueba Dirección o Administrador/IT.

El cierre conserva mes, usuario y fecha de aprobación, corte, versión de
fórmulas, incidencias, usuario/fecha/motivo de reapertura y eventos de auditoría.
El snapshot reproducible contiene únicamente el universo del bloque: Comerciales
incluye su auditoría de reseñas; Delegaciones sus filas; Área Manager incluye las
variantes necesarias por zona. Los registros legacy existentes se conservan con
scope `legacy`; no se convierten automáticamente en cierres de los tres bloques.

Una modificación posterior de Salesforce no sobrescribe un cierre definitivo.
Por defecto se registra en el libro de ajustes del siguiente mes abierto. Para
recalcular el mes original hay que reabrirlo manualmente con motivo obligatorio.
El ajuste conserva operación, mes original, mes de aplicación, importe, motivo,
usuario, fecha y estado.

## Universos por pestaña

Las pestañas pueden tener universos diferentes. La reconciliación sigue:

```text
base común - exclusiones de la pestaña + inclusiones especiales = total mostrado
```

Los IDs y motivos permanecen en detalles y exportaciones. El cuadro interno de
conciliación del universo solo se muestra a Administrador/IT.

Resumen funcional:

- Comerciales: operaciones elegibles por Salesforce User ID y reglas económicas
  mensuales.
- Delegaciones: entregas y rentabilidad según sus reglas de tienda.
- Call Center: ventas/compras/cambios, German y Facilitea según captador y
  configuración.
- Contact Center: citas y resultados con su ventana funcional vigente.
- Área Manager: ámbito de zona y delegaciones normalizadas.
- Financieros: ventas/cambios por fecha de firma y zona financiera. Los bloques 1
  y 3 usan todo el universo elegible; el bloque 2 usa solo operaciones con interés
  informado y no excluido. Carlos/Cristina conservan los tres bloques; desde
  2026-06 Irene/Nuria usan exclusivamente comisión neta por 0,005. La comisión
  final se recalcula sobre la réplica local.

La identidad principal de una persona es Salesforce User ID; el nombre es solo
una etiqueta y no debe usarse para unir registros.

## Reseñas

La fórmula actual no se ha cambiado:

- numerador: reseñas creadas en el mes, atribuidas por `OwnerId`;
- denominador: operaciones elegibles del responsable en ese mes.

El ratio puede superar el 100 % porque los universos no son uno-a-uno y puede
haber varias reseñas por operación. La pantalla lo explica en `Cómo se calcula`
y `/informes/comisiones-comerciales/export/reviews-audit.csv` permite auditar
cada reseña y su procedencia.

Cambiar a una reseña máxima por Opportunity exigiría una nueva decisión
funcional; no se aplica implícitamente.

### Seguridad del endpoint interno de reseñas

`INTERNAL_REVIEWS_ENDPOINT`, `INTERNAL_REVIEWS_USER` e
`INTERNAL_REVIEWS_PASSWORD` se proporcionan exclusivamente mediante variables
de entorno y no tienen valores fallback. Si falta cualquiera de ellas, el
servicio queda `not_configured`, no construye una cabecera Basic Auth y mantiene
el cero previsto por la lógica funcional vigente. Los fallos de transporte o
remotos conservan un estado técnico separado sin incluir credenciales, cabeceras
ni cuerpos de respuesta en logs o excepciones.

Una incidencia `not_configured`, de transporte o remota deja reseñas en cero y
rating en nulo donde procede, sin romper el informe ni bloquear por sí sola el
cierre de un bloque. Debe revisarse mediante los avisos administrativos del
informe y los logs técnicos sanitizados.

## Regla financiera especial por responsable

El responsable financiero se resuelve mediante una clave técnica estable de
`zona_financiera__c`, con fallback por delegación cuando la zona está vacía.
`Opportunity.OwnerId` identifica al comercial propietario y no selecciona esta
regla. Desde `2026-06`, `zona_nuria` y `zona_irene` reciben el 0,50 % de la
comisión neta, sustituyendo completamente los bloques 1, 2 y 3. El nombre sigue
siendo únicamente una etiqueta visual.

La regla histórica atribuida a Oscar del 40 % no aparece en la especificación
económica vigente; permanece desactivada hasta una aprobación funcional expresa
y no existe ninguna fila sintética basada en su nombre. Financieros es un bloque
operativo, por lo que no participa en los snapshots de cierre de Comerciales,
Delegaciones o Área Manager.

## Roles y datos sensibles

- Dirección y Administrador/IT: acceso completo al informe; ambos gestionan
  cierres.
- Área Manager: únicamente su zona.
- Responsable de delegación: únicamente su delegación; la delegación es
  obligatoria al asignar el rol.
- Financiero: pestaña Financieros y datos permitidos.
- Comercial: únicamente su Salesforce User ID.
- Auditor de comisiones: informe de Comisiones, búsqueda autorizada de managers
  y carga de Penalizaciones financieras.

El Auditor de comisiones no recibe administración de usuarios, configuración de
fórmulas ni cierres. Márgenes, penalizaciones y comisiones globales se filtran en
servidor; ocultar una pestaña en Blade no sustituye la autorización de endpoint,
exportación, fila y columna.

## Penalizaciones financieras

Ruta: `/informes/penalizaciones-financiacion`.

Las cargas XLSX se conservan como historial. Una carga nueva sustituye las filas
activas de los meses incluidos sin volver a sumar las versiones anteriores. Los
registros sin match con Salesforce se muestran para revisión y no se aplican de
forma silenciosa.

## Operación y auditoría

- Dashboard: `/informes/comisiones-comerciales`.
- XLSX: `/informes/comisiones-comerciales/export/comisiones.xlsx`.
- CSV entregas: `/informes/comisiones-comerciales/export/delegation-deliveries.csv`.
- CSV sin captador: `/informes/comisiones-comerciales/export/call-center-missing-captador.csv`.
- CSV reseñas: `/informes/comisiones-comerciales/export/reviews-audit.csv`.
- Configuración: `/informes/configuracion-comisiones`.
- Penalizaciones: `/informes/penalizaciones-financiacion`.

```bash
php artisan salesforce:sync-opportunities --all-history
php artisan salesforce:sync-commercial-reviews --all
php artisan salesforce:sync-tasaciones --all
```

Los reprocesados de datos operativos no modifican snapshots definitivos salvo
reapertura autorizada.

Archivos principales:

- `app/Services/Reports/CommercialCommissions/CommissionMonthResolver.php`;
- `app/Services/Reports/CommercialCommissions/CommercialCommissionDashboardService.php`;
- `app/Services/Reports/CommercialCommissions/CommercialCommissionClosureService.php`;
- `app/Services/Reports/CommercialCommissions/AreaRestrictedCommissionScope.php`;
- servicios de Call Center, Contact Center, Área Manager y Financieros.
