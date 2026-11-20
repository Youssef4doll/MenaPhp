import { fr } from "/home/claude/mena/src/i18n/fr";
import { ar } from "/home/claude/mena/src/i18n/ar";
// Every text with values, called with numbers and with words: the PHP texts must say the same.
const out: { lang: string; path: string; args: unknown[]; text: string }[] = [];
const walk = (lang: string, o: unknown, path: string) => {
  if (typeof o === "function") {
    const n = o.length || (o.toString().match(/^\(?([^)=]*)/)?.[1].split(",").filter(Boolean).length ?? 1);
    const sets: unknown[][] = [[1], [2], [3], [11]].map(([v]) => Array.from({ length: n }, () => v));
    // Words only where the text does not compare its values with numbers (those are only ever given numbers).
    if (!/[<>]=?\s*\d|===?\s*\d/.test(o.toString())) sets.push(Array.from({ length: n }, (_, i) => `W${i}`));
    for (const args of sets) out.push({ lang, path, args, text: String((o as (...a: unknown[]) => unknown)(...args)) });
    return;
  }
  if (o && typeof o === "object") for (const [k, v] of Object.entries(o)) walk(lang, v, path ? `${path}.${k}` : k);
};
walk("fr", fr, ""); walk("ar", ar, "");
console.log(JSON.stringify(out));
