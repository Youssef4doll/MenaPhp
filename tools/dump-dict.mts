import { fr } from "/home/claude/mena/src/i18n/fr";
import { ar } from "/home/claude/mena/src/i18n/ar";
const walk = (o: unknown): unknown => typeof o === "function" ? { __fn: o.toString() } : Array.isArray(o) ? o.map(walk) : o && typeof o === "object" ? Object.fromEntries(Object.entries(o).map(([k, v]) => [k, walk(v)])) : o;
console.log(JSON.stringify({ fr: walk(fr), ar: walk(ar) }));
