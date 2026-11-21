// The product editor's editor-state.ts, for the PHP version. Next.js opens another product without
// reloading, so the message for it ("Copie créée…", "… enregistré") waits in memory; here each product
// is a page load, so it waits in this tab's sessionStorage. Everything else is the original's.
import { afterSave, formKey, mayLeave, setUnsaved, type Held } from "@mena/editor-state";

export { afterSave, formKey, mayLeave, setUnsaved };
export type { Held };

type Msg = { ok: boolean; text: string };
type Carried = { to: string; msg: Msg; draft?: unknown };
const KEY = "mena_admin_carried";

function read(): Carried | null {
  try {
    return JSON.parse(sessionStorage.getItem(KEY) ?? "null") as Carried | null;
  } catch {
    return null;
  }
}

let carried: Carried | null = read();

/** A message for the editor of product `to`, shown when it opens; `draft`: the form to open instead of the saved rows. */
export function carryMessage(to: string, msg: Msg, draft?: unknown) {
  carried = { to, msg, draft };
  try {
    sessionStorage.setItem(KEY, JSON.stringify(carried));
  } catch {}
}

export function carriedMessage(id: string | null): Msg | null {
  return carried && carried.to === id ? carried.msg : null;
}

export function carriedDraft(id: string | null): unknown {
  return carried && carried.to === id ? carried.draft : undefined;
}

/** Called once the editor has shown it: a later visit starts without it. */
export function dropCarried() {
  carried = null;
  try {
    sessionStorage.removeItem(KEY);
  } catch {}
}
