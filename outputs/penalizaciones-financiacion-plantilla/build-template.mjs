import { fileURLToPath } from "node:url";
import { SpreadsheetFile, Workbook } from "@oai/artifact-tool";

const outputDir = fileURLToPath(new URL(".", import.meta.url));
const workbook = Workbook.create();
const sheet = workbook.worksheets.add("Penalizaciones");

sheet.showGridLines = false;
sheet.mergeCells("A1:E1");
sheet.getRange("A1").values = [["Plantilla de penalizaciones de financiacion"]];
sheet.getRange("A1:E1").format = {
    fill: "#12355B",
    font: { bold: true, color: "#FFFFFF", size: 16 },
    horizontalAlignment: "center",
    verticalAlignment: "center",
};
sheet.getRange("A1:E1").format.rowHeight = 30;

sheet.mergeCells("A2:E2");
sheet.getRange("A2").values = [["Completa una fila por penalizacion. Las columnas A, B, C y D son obligatorias."]];
sheet.getRange("A2:E2").format = {
    fill: "#E8F1FA",
    font: { bold: true, color: "#12355B" },
    wrapText: true,
    verticalAlignment: "center",
};

sheet.mergeCells("A3:E3");
sheet.getRange("A3").values = [["Mes comision: usa YYYY-MM (ejemplo: 2026-06). ID comercial: usa el ID de Salesforce del propietario de las oportunidades."]];
sheet.getRange("A3:E3").format = {
    fill: "#F7FAFD",
    font: { color: "#334155" },
    wrapText: true,
    verticalAlignment: "center",
};

sheet.mergeCells("A4:E4");
sheet.getRange("A4").values = [["descontar comercial 4%: introduce el importe a descontar. Puede ser positivo o negativo; el sistema siempre lo aplicara como penalizacion negativa. Varias filas del mismo ID y mes se suman."]];
sheet.getRange("A4:E4").format = {
    fill: "#F7FAFD",
    font: { color: "#334155" },
    wrapText: true,
    verticalAlignment: "center",
};
sheet.getRange("A2:E4").format.rowHeight = 32;

sheet.getRange("A6:E6").values = [[
    "Mes comision",
    "Nombre comercial",
    "ID comercial",
    "descontar comercial 4%",
    "Observacion (opcional)",
]];
sheet.getRange("A6:E6").format = {
    fill: "#1F5D8F",
    font: { bold: true, color: "#FFFFFF" },
    horizontalAlignment: "center",
    verticalAlignment: "center",
    wrapText: true,
    borders: { preset: "outside", style: "thin", color: "#17456B" },
};
sheet.getRange("A6:E6").format.rowHeight = 28;

sheet.getRange("A7:E31").format = {
    fill: "#FFFFFF",
    borders: { preset: "inside", style: "thin", color: "#D8E1EA" },
};
sheet.getRange("A7:A31").format.numberFormat = "@";
sheet.getRange("B7:B31").format.numberFormat = "@";
sheet.getRange("C7:C31").format.numberFormat = "@";
sheet.getRange("D7:D31").format.numberFormat = "#,##0.00";
sheet.getRange("D7:D31").format.horizontalAlignment = "right";
sheet.getRange("E7:E31").format.wrapText = true;

sheet.getRange("A33:E33").merge();
sheet.getRange("A33").values = [["Ejemplo de una fila valida: 2026-06 | Comercial Uno | 005XXXXXXXXXXXX | 125,50 | Cancelacion anticipada"]];
sheet.getRange("A33:E33").format = {
    fill: "#FFF7E8",
    font: { italic: true, color: "#7A4C00" },
    wrapText: true,
};

sheet.getRange("A1:A33").format.columnWidth = 17;
sheet.getRange("B1:B33").format.columnWidth = 28;
sheet.getRange("C1:C33").format.columnWidth = 24;
sheet.getRange("D1:D33").format.columnWidth = 28;
sheet.getRange("E1:E33").format.columnWidth = 42;
sheet.freezePanes.freezeRows(6);

const xlsx = await SpreadsheetFile.exportXlsx(workbook);
await xlsx.save(`${outputDir}/Plantilla_penalizaciones_financiacion.xlsx`);
