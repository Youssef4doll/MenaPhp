// app/admin/(panel)/produits/page.tsx: the same page, its numbers read by PHP (AdminPages::products).
import Link from "next/link";
import { Empty, PageTitle, Tabs, btn } from "@/components/admin/ui";
import { DemoBanner, ListFilters, PendingHint } from "@/app/admin/(panel)/produits/ProductListControls";
import { IntentLink } from "@/app/admin/(panel)/produits/IntentLink";
import { ProductRows, type ProductRow } from "@/app/admin/(panel)/produits/ProductRows";
import { listHref, type ListParams } from "@/app/admin/(panel)/produits/list-params";

type Props = {
  params: ListParams;
  page: number;
  pages: number;
  total: number;
  online: number;
  offline: number;
  outCount: number;
  uncheckedCount: number;
  demoCount: number;
  untranslated: number;
  categories: { id: string; name: string; count: number }[];
  rows: ProductRow[];
};

export default function ProductsPage({ params, page, pages, total, online, offline, outCount, uncheckedCount, demoCount, untranslated, categories, rows }: Props) {
  const filtered = params.q !== "" || params.c !== "";
  const found = online + offline;
  const tab = (f: typeof params.f) => listHref(params, { f, page: 1 });

  return (
    <>
      <PageTitle
        title="Produits"
        sub={`${found} ${found > 1 ? "produits" : "produit"}${filtered ? (found > 1 ? " trouvés" : " trouvé") : ""} · vues et ventes sur 30 jours.`}
        actions={
          <>
            <IntentLink href="/admin/produits/nouveau" className={btn.sea}>Nouveau produit</IntentLink>
            <Link href="/admin/produits/fournisseur" className={btn.ghost}>Depuis Ecomness</Link>
            <Link href="/admin/produits/import" className={btn.ghost}>Importer (CSV)</Link>
            {/* A file to save, not a page: the browser downloads it. */}
            <a href="/admin/produits/export" download className={btn.ghost}>Exporter (CSV)</a>
            <Link href="/admin/produits/traduction" className={btn.ghost} aria-label={untranslated > 0 ? `Traduire en lot : ${untranslated} produits sans nom arabe` : undefined}>
              Traduire en lot
              {untranslated > 0 && <span className="rounded-full bg-sand px-2 py-0.5 text-[11px] font-semibold tabular-nums text-ink-2" title="Produits sans nom arabe">{untranslated}</span>}
            </Link>
          </>
        }
      />
      {demoCount > 0 && <DemoBanner count={demoCount} />}
      <Tabs
        current={params.f}
        items={[
          { key: "all", label: "Tous", href: tab("all"), count: found },
          { key: "active", label: "En ligne", href: tab("active"), count: online },
          { key: "inactive", label: "Hors ligne", href: tab("inactive"), count: offline },
          { key: "out", label: "Épuisés", href: tab("out"), count: outCount },
          { key: "unchecked", label: "À vérifier", href: tab("unchecked"), count: uncheckedCount },
        ]}
      />
      <ListFilters params={params} categories={categories} />
      {rows.length === 0 ? (
        filtered || params.f !== "all" ? (
          <Empty>
            Aucun produit ne correspond.{" "}
            <Link href="/admin/produits" className="text-sea underline underline-offset-4">Voir tous les produits</Link>
          </Empty>
        ) : (
          <Empty>Aucun produit. Ajoutez le premier avec « Nouveau produit », ou plusieurs d&apos;un coup avec « Importer (CSV) ».</Empty>
        )
      ) : (
        <ProductRows key={listHref(params)} rows={rows} categories={categories.map(({ id, name }) => ({ id, name }))} />
      )}
      {pages > 1 && (
        <nav aria-label="Pages" className="mt-4 flex flex-wrap items-center justify-between gap-2">
          {page > 1 ? (
            <Link href={listHref(params, { page: page - 1 })} className={btn.ghost} rel="prev">
              ← Précédente <PendingHint />
            </Link>
          ) : (
            <span />
          )}
          <span className="order-first basis-full text-center text-sm tabular-nums text-muted sm:order-none sm:basis-auto">
            Page {page} sur {pages} · {total} produit{total > 1 ? "s" : ""}
          </span>
          {page < pages ? (
            <Link href={listHref(params, { page: page + 1 })} className={btn.ghost} rel="next">
              Suivante → <PendingHint />
            </Link>
          ) : (
            <span />
          )}
        </nav>
      )}
    </>
  );
}
