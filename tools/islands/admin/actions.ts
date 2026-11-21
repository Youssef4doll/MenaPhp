// The back office's Server Actions, for the PHP version: each "use server" file of the Next.js back
// office is replaced at build time by calls to this function (see build.mjs, serverActions), and PHP
// runs the action (src/admin/Actions.php). As with Next.js, an action that changed something answers
// with the current page drawn again from the database, in the same response; it is shown in the same
// transition as the action's own result.
import { startTransition } from "react";

export type PageModel = { component: string; props: Record<string, unknown>; title: string; nav: unknown; admin: string };

type Setter = (page: PageModel) => void;
let setPage: Setter | null = null;

/** Called once by the admin's root: how a page drawn again by the server is shown. */
export function onPage(set: Setter) {
  setPage = set;
}

/** The page drawn again, as router.refresh() asks it of Next.js. */
export async function refreshPage(): Promise<void> {
  const res = await fetch(location.pathname + location.search, { headers: { "x-mena-page": "1" }, cache: "no-store" });
  if (res.status === 401) return void location.assign("/admin/connexion");
  if (!res.ok) return;
  show((await res.json()) as PageModel);
}

function show(page: PageModel) {
  document.title = page.title;
  startTransition(() => setPage?.(page));
}

/** A FormData argument travels as its own parts of a multipart request (files included). */
function encode(args: unknown[]): RequestInit {
  const forms: FormData[] = [];
  const payload = JSON.stringify(args, (_k, v) => {
    if (v instanceof FormData) {
      forms.push(v);
      return { $fd: forms.length - 1 };
    }
    // undefined stays undefined, as React sends it: a missing property, null in a list of arguments.
    return v;
  });
  const page = location.pathname + location.search;
  if (forms.length === 0) {
    return { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ page, args: JSON.parse(payload) }) };
  }
  const body = new FormData();
  const keys: [string, string][][] = forms.map(() => []);
  forms.forEach((f, i) => {
    let n = 0;
    for (const [k, v] of f.entries()) {
      const part = `fd${i}_${n++}`;
      keys[i].push([k, part]);
      body.append(part, v);
    }
  });
  body.append("__payload", JSON.stringify({ page, args: JSON.parse(payload), forms: keys }));
  return { method: "POST", body };
}

export async function callAction(module: string, name: string, args: unknown[]): Promise<unknown> {
  const init = encode(args);
  const res = await fetch(`/admin/api/action/${module}/${name}`, { ...init, cache: "no-store", headers: { ...(init.headers ?? {}), "x-mena-action": "1" } });
  if (res.status === 401) {
    // requireAdmin() redirects to the sign-in page, in an action too.
    location.assign("/admin/connexion");
    return new Promise(() => {});
  }
  const body = (await res.json().catch(() => null)) as { result?: unknown; page?: PageModel | null; redirect?: string; error?: string } | null;
  if (!body || !res.ok) throw new Error(body?.error || `Erreur du serveur (${res.status}).`);
  if (body.redirect) {
    location.assign(body.redirect);
    return new Promise(() => {});
  }
  if (body.page) show(body.page);
  return body.result;
}
