import fs from "node:fs/promises";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const inputPath = "Plantilla_penalizaciones_financiacion.xlsx";
const file = await FileBlob.load(inputPath);
const workbook = await SpreadsheetFile.importXlsx(file);
const inspection = await workbook.inspect({
    kind: "table",
    range: "Penalizaciones!A1:F10",
    include: "values,formulas",
    tableMaxRows: 10,
    tableMaxCols: 6,
});
console.log(inspection.ndjson);

const preview = await workbook.render({
    sheetName: "Penalizaciones",
    range: "A1:F33",
    scale: 1,
    format: "png",
});
await fs.writeFile("preview.png", new Uint8Array(await preview.arrayBuffer()));
console.log("Preview exported");
