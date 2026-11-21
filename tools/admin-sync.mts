// Copies from the Next.js shop, word for word, what the PHP back office must say the same way:
// the AI requests (src/admin/ai-prompts.json, sent to Claude by src/admin/Ai.php) and the
// columns of the products' CSV file (src/admin/csv-columns.json, src/admin/ProductCsv.php):
//
//   cd /path/to/Mena && NODE_OPTIONS=--conditions=react-server npx tsx /path/to/MenaPhp/tools/admin-sync.mts
//
// The requests built around a product are copied with stand-in values ("\u0001…"), which PHP
// replaces with the product's own: the words around them stay the Next.js shop's.
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { SYSTEM, taskFor } from "@/lib/translate-prompt";
import { SUPPLIER_SYSTEM, DRAFT_SCHEMA, draftTask } from "@/lib/supplier-prompt";
import { WEB_SYSTEM, REPORT_SCHEMA, webTask } from "@/lib/web-complete-prompt";
import { IDEAS_SYSTEM, IDEAS_ANSWER_SCHEMA, ideasTask } from "@/lib/image-ideas";
import { CATEGORIES_SYSTEM, CATEGORIES_SCHEMA, categoriesTask } from "@/lib/category-ar";
import { COLUMNS } from "@/lib/product-csv";

const here = path.dirname(fileURLToPath(import.meta.url));
const T = "\u0001";
const web = { ref: `${T}ref`, name: `${T}name`, nameAr: `${T}nameAr`, summary: `${T}summary`, description: `${T}description`, specs: `${T}specs` };
const ideas = { name: `${T}name`, summary: `${T}summary`, description: `${T}description`, specs: `${T}specs`, nameAr: `${T}nameAr`, descriptionAr: `${T}descriptionAr` };

const out = {
  translate: { system: SYSTEM, fill: taskFor("fill"), toAr: taskFor("to-ar") },
  supplier: { system: SUPPLIER_SYSTEM, schema: DRAFT_SCHEMA, task: draftTask({ text: `${T}text`, categories: [`${T}cat`] }) },
  web: { system: WEB_SYSTEM, schema: REPORT_SCHEMA, task: webTask(web), product: JSON.stringify(web) },
  ideas: { system: IDEAS_SYSTEM, schema: IDEAS_ANSWER_SCHEMA, task: ideasTask(ideas), texts: JSON.stringify(ideas, null, 1) },
  categories: { system: CATEGORIES_SYSTEM, schema: CATEGORIES_SCHEMA, task: categoriesTask([{ id: `${T}id`, name: `${T}name`, nameAr: "", headline: `${T}headline`, headlineAr: "" }]) },
};
fs.writeFileSync(path.join(here, "../src/admin/ai-prompts.json"), JSON.stringify(out, null, 1) + "\n");
fs.writeFileSync(path.join(here, "../src/admin/csv-columns.json"), JSON.stringify(COLUMNS.map(({ key, header, example }) => ({ key, header, example })), null, 1) + "\n");
console.log("src/admin/ai-prompts.json and csv-columns.json written");
