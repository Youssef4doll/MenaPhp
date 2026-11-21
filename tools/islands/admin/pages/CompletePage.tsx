// app/admin/(panel)/produits/completer/page.tsx: the same page, the products scored by PHP (AdminPages::complete).
import Link from "next/link";
import { PageTitle, Tabs } from "@/components/admin/ui";
import { CompleteBatch, type ToComplete } from "@/app/admin/(panel)/produits/completer/CompleteBatch";

type Props = { scope: "active" | "all"; ids: boolean; total: number; max: number; products: ToComplete[]; aiReady: boolean };

export default function CompletePage({ scope, ids, total, max, products, aiReady }: Props) {
  return (
    <div className="flex flex-col gap-4">
      <PageTitle
        title="Compléter depuis le web"
        sub="Photos, grandes images, vidéo et textes trouvés sur les pages du même produit (la marque, les grandes boutiques). Vous choisissez avant que rien ne soit enregistré."
        actions={<Link href="/admin/produits/qualite" className="text-sm text-sea underline underline-offset-4">Qualité des fiches</Link>}
      />
      {!ids && (
        <Tabs
          current={scope}
          items={[
            { key: "active", label: "En ligne", href: "/admin/produits/completer" },
            { key: "all", label: "Tous les produits", href: "/admin/produits/completer?f=all" },
          ]}
        />
      )}
      {total > max && <p className="text-sm text-muted">Les {max} fiches les plus faibles sur {total}.</p>}
      <CompleteBatch products={products} aiReady={aiReady} />
    </div>
  );
}
