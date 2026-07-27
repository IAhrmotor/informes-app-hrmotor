import fs from "node:fs/promises";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const inputPath = "Plantilla_penalizaciones_financiacion.xlsx";
const file = await FileBlob.load(inputPath);
const workbook = await SpreadsheetFile.importXlsx(file);
const inspection = await workbook.inspect({
    kind: "table",
    range: "Penalizaciones!A1:E10",
    include: "values,formulas",
    tableMaxRows: 10,
    tableMaxCols: 5,
});
console.log(inspection.ndjson);

const preview = await workbook.render({
    sheetName: "Penalizaciones",
    range: "A1:E33",
    scale: 1,
    format: "png",
});
await fs.writeFile("preview.png", new Uint8Array(await preview.arrayBuffer()));
console.log("Preview exported");
