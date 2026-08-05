# Informe de Comisiones

## Mes y estado

`CommissionMonthResolver` fija un unico mes para Comerciales, Delegaciones, Call Center, Contact Center, Area Manager, Financieros y XLSX. El mes actual se admite y siempre es Provisional.

Un cierre pasa por provisional, pendiente de aprobacion, definitivo y, si procede, reabierto. Solo Direccion o Administrador/IT preparan, aprueban o reabren. La aprobacion requiere fin de mes natural, cinco componentes confirmados y cero incidencias relevantes.

El snapshot definitivo contiene las seis vistas, variantes por zona de Area Manager, detalles, auditoria de resenas, formula, corte y estado de fuentes. La pantalla y el XLSX leen ese snapshot. Reabrir exige motivo y no elimina snapshots ni eventos previos.

Los ajustes guardan operacion, mes original, siguiente mes abierto, importe, motivo, usuario, fecha y estado.

Los XLSX se recortan en servidor: Financiero recibe solo Financieros; Comercial,
solo su Salesforce User ID; Responsable, solo su delegacion; Area Manager, solo
su zona. Los CSV especializados se ocultan y bloquean cuando su contenido no
corresponde al rol.

Cada pestana muestra el puente `base - exclusiones + inclusiones = total`
correspondiente a su universo. Los IDs y motivos permanecen en los detalles y
exports auditables; en bloques que combinan fuentes, el total se denomina
apariciones para no presentarlo falsamente como entidades distintas.

La conciliacion interna del universo solo se muestra a Administrador/IT. El rol
Auditor de comisiones puede abrir y cargar Penalizaciones financieras, pero no
recibe permisos de configuracion de formulas, cierres ni administracion.

## Resenas

No se cambio la formula: se cuentan resenas creadas en el mes por `OwnerId` y se dividen entre operaciones elegibles del responsable. Puede superar 100% porque los universos no son uno-a-uno y puede haber mas de una resena por operacion. El CSV `reviews-audit.csv` muestra procedencia y oportunidades relacionadas.
