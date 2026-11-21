// app/admin/(panel)/produits/traduction/page.tsx: the same page, the products to translate chosen by PHP.
import Link from "next/link";
import { TranslateBatch } from "@/app/admin/(panel)/produits/traduction/TranslateBatch";

type Props = Parameters<typeof TranslateBatch>[0] & { ids: boolean; total: number; max: number };

export default function TranslateBatchPage({ products, aiReady, ids, total, max }: Props) {
  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-3">
        <Link href="/admin/produits" className="inline-flex h-10 items-center text-sm text-muted">← Produits</Link>
        <h1 className="font-display text-[26px]">Traduire en lot</h1>
      </div>
      {ids && (
        <p className="flex flex-wrap items-center gap-x-1 text-sm text-muted">
          Produits choisis seulement.
          <Link href="/admin/produits/traduction" className="inline-flex min-h-10 items-center text-sea underline underline-offset-4">Voir tous les produits à traduire</Link>
        </p>
      )}
      {total > max && (
        <p className="rounded-2xl bg-[#fff3d6] px-4 py-3 text-sm text-[#8a5a00]">
          {total} produits ont des textes à traduire : voici les {max} plus récents. Revenez sur cette page ensuite pour les suivants.
        </p>
      )}
      <TranslateBatch products={products} aiReady={aiReady} chosen={ids} />
    </div>
  );
}
