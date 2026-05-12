# Reglas de negocio - Dashboard de Leads

## 1. Objetivo

Normalizar los leads comerciales para mostrar un dashboard ejecutivo en Laravel.

El dashboard debe sustituir el informe mensual comercial en PDF como fuente principal de consulta.

## 2. Lecturas principales

Todos los KPIs relevantes deben poder calcularse en tres lecturas:

### Con Exposición

Incluye todos los leads de Salesforce.

### Sin Exposición

Excluye leads cuyo portal normalizado sea `Exposición`.

Esta vista permite analizar captación real sin leads recreados manualmente por comerciales.

### Solo Exposición

Incluye únicamente leads cuyo portal normalizado sea `Exposición`.

Sirve para controlar este canal y evitar que distorsione la lectura de captación.

## 3. Canal de Dirección

### Regla

Si: Medio_Nuevo__c = "Llamada"
Entonces: canal_direccion = "Llamada"

En cualquier otro caso: canal_direccion = "Formulario"
Campo prioritario para detectar llamadas se usa: "Medio_Nuevo__c"
No se debe usar como campo principal: "LEA_SEL_Medio_Origen__c"

Motivo: el Flow “Canal PORTALES” puede cambiar ese campo a anuncio cuando la fuente es un portal.

## 4. Portal original
Si el lead es llamada

Si:

canal_direccion = "Llamada"

Entonces:

portal_original = Fuente_Nuevo__c
Si el lead es formulario

Si:

canal_direccion = "Formulario"

Entonces se usa esta prioridad inicial:

Portal
LEA_SEL_Fuente_Origen__c
Fuente_Nuevo__c
Sin portal

## 5. Grupo portal

El grupo portal se obtiene desde tabla maestra.

Ejemplos:

Coches.net => Coches.net
1000Anuncios => 1000Anuncios
Wallapop => Wallapop
Sumauto => Autocasion / Sumauto
Autocasion => Autocasion / Sumauto
Milanuncios => Milanuncios
Web => Web
Google Maps => Google Maps
Meta => Meta
Exposición => Exposición
6. Exposición

Si:

portal_original = "Exposición"

Entonces:

es_exposicion = true

En caso contrario:

es_exposicion = false
7. Delegación para llamadas

Para llamadas se usa como campo base:

Delegacion_Encargada_Text__c

La normalización debe cruzar:

Portal + Delegacion_Encargada_Text__c

Resultado esperado:

tipo
delegacion_real
grupo_comercial

Esto debe salir de una tabla maestra editable.

8. Delegación para formularios

Si:

canal_direccion = "Formulario"

y:

Remitente Lead informado

Entonces se cruza:

Portal + Remitente Lead

Resultado esperado:

cuenta_receptora
delegacion_real
grupo_comercial

No se debe mapear directamente un email a delegación sin validar contra tabla maestra.

9. Formularios sin Remitente Lead

Si el lead es formulario y no tiene Remitente Lead, usar esta prioridad:

Delegacion_Encargada_Bueno__c
Delegacion_Encargada__c
Delegación
Delegación del propietario/persona que trabajó el lead si es Exposición
Sin clasificar
10. Estados
Convertido

Si:

Status = "Convertido"

Entonces:

convertido = true
Descartado

Se mantiene la lógica actual del informe mensual comercial.

Hasta cerrar la lógica exacta, el servicio deja preparado el campo:

descartado
11. Calidad de dato

Deben detectarse incidencias como:

Formularios sin Remitente Lead.
Remitente Lead no mapeado.
Llamadas sin delegación.
Portal sin grupo portal.
Delegación no reconocida.
Exposición sin propietario/delegación trabajador.

Los registros dudosos deben quedar identificados y no mezclarse visualmente con datos fiables.

12. Coches.net

Coches.net tiene una regla histórica y una futura.

Debe existir tabla de vigencias:

portal_original
regla
fecha_desde
fecha_hasta
estado

Reglas previstas:

Coches.net | agrupaciones históricas | inicio | fecha cambio | histórico
Coches.net | 1 mail + 1 número por delegación | fecha cambio | actual | activo

La fecha exacta del cambio queda pendiente, pero la estructura no debe bloquear el desarrollo.

13. Pestañas del front
Resumen Dirección

Debe mostrar:

Total leads.
Llamadas.
Formularios.
Convertidos.
% conversión.
% descarte.
Potenciales.
Pendientes de clasificar.

También debe mostrar lectura:

Con Exposición.
Sin Exposición.
Solo Exposición.
KPIs clave

Cada KPI debe mostrarse en columnas:

Métrica | Con Exposición | Sin Exposición | Diferencia

Además debe existir bloque específico de Solo Exposición.

Procedencia por portal

Columnas:

Portal
Llamadas
Formularios
Total
% llamadas
% formularios
Convertidos
% conversión
Detalle de portal

Columnas:

Portal
Grupo comercial
Delegación real
Llamadas
Formularios
Total
Convertidos
% conversión
Delegaciones

Vista principal:

Delegación / grupo
Total leads
Llamadas
Formularios
Convertidos
% conversión
% descarte

Al seleccionar delegación:

Delegación
Portal
Llamadas
Formularios
Total
Convertidos
% conversión
Comerciales

Debe permitir filtrar por:

Periodo.
Portal.
Grupo portal.
Canal.
Delegación real.
Grupo comercial.
Comercial.
Estado del lead.
Comparativa entre periodos

Debe comparar:

Métrica
Periodo actual
Periodo comparado
Diferencia
Calidad de dato

Debe mostrar:

Incidencia
Registros
Acción