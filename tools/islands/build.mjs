// Builds public/assets/islands.js: the Next.js shop's own interactive components
// (product page, quick order, basket, …) compiled for the PHP shop's pages.
//
//   node tools/islands/build.mjs /path/to/Mena        (the Next.js shop's folder, with node_modules)
//
// next/link, next/navigation, next/image and next/dynamic are replaced by the
// small files beside this one; everything else is the Next.js shop's code as it is.
import { createRequire } from "node:module";
import { fileURLToPath } from "node:url";
import path from "node:path";
import fs from "node:fs";

const here = path.dirname(fileURLToPath(import.meta.url));
const mena = path.resolve(process.argv[2] || "../Mena");
const require = createRequire(path.join(mena, "package.json"));
const esbuild = require("esbuild");

const outdir = path.join(here, "../../public/assets/islands");
fs.rmSync(outdir, { recursive: true, force: true });
const result = await esbuild.build({
  entryPoints: { islands: path.join(here, "entry.tsx") },
  outdir,
  entryNames: "[name]",
  chunkNames: "c-[hash]",
  bundle: true,
  // Split like the Next.js shop: what only some visitors need (the photo viewer, the video player) is its own file.
  format: "esm",
  splitting: true,
  minify: !process.env.DEV,
  target: "es2020",
  jsx: "automatic",
  legalComments: "none",
  tsconfig: path.join(mena, "tsconfig.json"),
  nodePaths: [path.join(mena, "node_modules")],
  absWorkingDir: mena,
  // What Next.js writes into its pages when it builds them: here the main language is French (at the
  // root, Arabic under /ar, as the PHP router serves them), and the shop's address comes from config.php
  // at run time (the page's <body data-site>, read by shop.js).
  define: {
    // DEV=1: React's development build, which names every difference between the server's markup and its own.
    "process.env.NODE_ENV": process.env.DEV ? '"development"' : '"production"',
    "process.env.NEXT_PUBLIC_DEFAULT_LANG": '"fr"',
    "process.env.NEXT_PUBLIC_SITE_URL": "globalThis.MENA_SITE_URL",
  },
  alias: {
    "next/link": path.join(here, "shims-next-link.tsx"),
    "next/navigation": path.join(here, "shims-next-navigation.ts"),
    "next/image": path.join(here, "shims-next-image.tsx"),
    "next/dynamic": path.join(here, "shims-next-dynamic.tsx"),
    "@/lib/client/cart": path.join(here, "shims-cart.ts"),
    "@/app/[lang]/suivi/actions": path.join(here, "shims-suivi-actions.ts"),
    // The real basket, for the file above.
    "@mena/lib/client/cart": path.join(mena, "src/lib/client/cart.ts"),
  },
  metafile: true,
  logLevel: "warning",
});
for (const [f, o] of Object.entries(result.metafile.outputs)) console.log(`${(o.bytes / 1024).toFixed(0).padStart(5)} KB  ${path.basename(f)}`);
const out = Object.entries(result.metafile.outputs).find(([f]) => f.endsWith("islands.js"));
const big = Object.entries(out[1].inputs).sort((a, b) => b[1].bytesInOutput - a[1].bytesInOutput).slice(0, 25);
if (process.env.SIZES) for (const [f, i] of big) console.log((i.bytesInOutput / 1024).toFixed(1).padStart(7), f);
console.log(`${out[0]}: ${(out[1].bytes / 1024).toFixed(0)} KB`);
