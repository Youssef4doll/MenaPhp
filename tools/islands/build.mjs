// Builds public/assets/islands.js: the Next.js shop's own interactive components
// (product page, quick order, basket, …) compiled for the PHP shop's pages.
//
//   node tools/islands/build.mjs /path/to/Mena        (the Next.js shop's folder, with node_modules)
//
// next/link, next/navigation, next/image and next/dynamic are replaced by the
// small files beside this one; everything else is the Next.js shop's code as it is.
//
// admin.js is the Next.js back office's pages, drawn in the browser from what PHP reads in the
// database (src/admin). Its Server Actions ("use server" files) become calls to PHP: see
// serverActions below and admin/actions.ts.
import { createRequire } from "node:module";
import { fileURLToPath } from "node:url";
import path from "node:path";
import fs from "node:fs";

const here = path.dirname(fileURLToPath(import.meta.url));
const mena = path.resolve(process.argv[2] || "../Mena");
const require = createRequire(path.join(mena, "package.json"));
const esbuild = require("esbuild");

const outdir = path.join(here, "../../public/assets/islands");
const adminDir = path.join(mena, "src/app/admin");
const srcDir = path.join(mena, "src");

/**
 * A "use server" file of the Next.js back office, replaced by its calls: each exported function asks
 * PHP to run the action of the same name ("produits/actions" + "saveProduct" → /admin/api/action/…).
 * Its types are only read by TypeScript, so nothing else is needed.
 */
/** The product editor's message for the next product, kept across a page load (shims-editor-state.ts). */
const editorState = {
  name: "editor-state",
  setup(b) {
    b.onResolve({ filter: /^\.\/editor-state$/ }, (args) => (args.importer.includes(`${path.sep}produits${path.sep}[id]${path.sep}`) ? { path: path.join(here, "shims-editor-state.ts") } : undefined));
  },
};

const serverActions = {
  name: "server-actions",
  setup(b) {
    b.onLoad({ filter: /\.tsx?$/ }, async (args) => {
      if (!args.path.startsWith(srcDir)) return undefined;
      const src = await fs.promises.readFile(args.path, "utf8");
      if (!/^\s*["']use server["']/.test(src)) return undefined;
      const names = [...src.matchAll(/export\s+async\s+function\s+(\w+)/g)].map((m) => m[1]);
      const id = path.relative(adminDir, args.path).split(path.sep).join("/").replace(/\.tsx?$/, "").replace(/^\(panel\)\//, "");
      if (id.startsWith("..")) throw new Error(`Server Actions outside the back office: ${args.path}`);
      const lines = names.map((n) => `export const ${n} = (...a) => callAction(${JSON.stringify(id)}, ${JSON.stringify(n)}, a);`);
      return { contents: `import { callAction } from ${JSON.stringify(path.join(here, "admin/actions.ts"))};\n${lines.join("\n")}\n`, loader: "js", resolveDir: path.dirname(args.path) };
    });
  },
};
fs.rmSync(outdir, { recursive: true, force: true });
const result = await esbuild.build({
  entryPoints: { islands: path.join(here, "entry.tsx"), admin: path.join(here, "admin/entry.tsx") },
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
    "@vercel/blob/client": path.join(here, "shims-vercel-blob.ts"),
    // The editor's own state, for the file above.
    "@mena/editor-state": path.join(mena, "src/app/admin/(panel)/produits/[id]/editor-state.ts"),
    // The real basket, for the file above.
    "@mena/lib/client/cart": path.join(mena, "src/lib/client/cart.ts"),
  },
  plugins: [serverActions, editorState],
  metafile: true,
  logLevel: "warning",
});
for (const [f, o] of Object.entries(result.metafile.outputs)) console.log(`${(o.bytes / 1024).toFixed(0).padStart(5)} KB  ${path.basename(f)}`);
// The loading outlines, for PHP (src/admin/AdminApp.php reads them).
const tmp = path.join(mena, ".mena-php-static.mjs");
await esbuild.build({
  entryPoints: [path.join(here, "admin/static.tsx")],
  outfile: tmp,
  bundle: true,
  platform: "node",
  format: "esm",
  packages: "external",
  jsx: "automatic",
  tsconfig: path.join(mena, "tsconfig.json"),
  nodePaths: [path.join(mena, "node_modules")],
  absWorkingDir: mena,
  define: { "process.env.NODE_ENV": '"production"' },
  logLevel: "warning",
});
const { skeletons } = await import(`${tmp}?${Date.now()}`);
fs.rmSync(tmp);
fs.writeFileSync(path.join(outdir, "skeletons.json"), JSON.stringify(skeletons));

for (const name of ["islands.js", "admin.js"]) {
  const out = Object.entries(result.metafile.outputs).find(([f]) => f.endsWith(name));
  const big = Object.entries(out[1].inputs).sort((a, b) => b[1].bytesInOutput - a[1].bytesInOutput).slice(0, 25);
  if (process.env.SIZES) for (const [f, i] of big) console.log((i.bytesInOutput / 1024).toFixed(1).padStart(7), f);
  console.log(`${out[0]}: ${(out[1].bytes / 1024).toFixed(0)} KB`);
}
