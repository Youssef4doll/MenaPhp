// The Next.js back office in the PHP version: its menu (components/admin/Shell) and its pages,
// drawn in the browser. PHP reads the database and writes the page's data into the HTML
// (<script id="mena-admin">); each page below is the JSX of the Next.js page of the same name,
// with its database reads replaced by that data.
import { createRoot } from "react-dom/client";
import { lazy, Suspense, useState, type ComponentType } from "react";
import { Shell, type NavItem } from "@/components/admin/Shell";
import PanelLoading from "@/app/admin/(panel)/loading";
import ProductsLoading from "@/app/admin/(panel)/produits/loading";
import { onPage, type PageModel } from "./actions";

type Page = ComponentType<Record<string, unknown>>;
const page = (load: () => Promise<{ default: unknown }>) => lazy(load as () => Promise<{ default: Page }>);

/** One file per page, loaded with it: a page never waits for the code of the others. */
const PAGES: Record<string, Page> = {
  LoginPage: page(() => import("./pages/LoginPage")),
  ProductsPage: page(() => import("./pages/ProductsPage")),
  ProductEditPage: page(() => import("./pages/ProductEditPage")),
  ImportPage: page(() => import("./pages/ImportPage")),
  SupplierImportPage: page(() => import("./pages/SupplierImportPage")),
  CompletePage: page(() => import("./pages/CompletePage")),
  TranslateBatchPage: page(() => import("./pages/TranslateBatchPage")),
  QualityPage: page(() => import("./pages/QualityPage")),
  NotFoundPage: page(() => import("./pages/NotFoundPage")),
};

/** The layout's logout: the session ends on the server, then the sign-in page. */
async function logout() {
  await fetch("/admin/deconnexion", { method: "POST", headers: { "x-mena-action": "1" } }).catch(() => null);
  location.assign("/admin/connexion");
}

function App({ first }: { first: PageModel }) {
  const [model, setModel] = useState(first);
  onPage(setModel);
  const P = PAGES[model.component] ?? PAGES.NotFoundPage;
  const body = (
    <Suspense fallback={location.pathname.startsWith("/admin/produits") ? <ProductsLoading /> : <PanelLoading />}>
      <P {...model.props} />
    </Suspense>
  );
  if (!model.nav) return <Suspense fallback={null}><P {...model.props} /></Suspense>;
  return (
    <Shell nav={model.nav as NavItem[]} admin={model.admin} logout={logout}>
      {body}
    </Shell>
  );
}

const data = document.getElementById("mena-admin");
const root = document.getElementById("admin-root");
if (data && root) {
  const first = JSON.parse(data.textContent || "{}") as PageModel;
  createRoot(root).render(<App first={first} />);
}
