// app/admin/(panel)/produits/[id]/page.tsx: the same page, the product read by PHP (AdminPages::product).
import { ProductEditor } from "@/app/admin/(panel)/produits/[id]/ProductEditor";
import { LeaveLink } from "@/app/admin/(panel)/produits/[id]/LeaveLink";

type EditorProps = Parameters<typeof ProductEditor>[0];
type Props = { name: string | null; slug: string | null; active: boolean; prev: string | null; next: string | null; editor: EditorProps };

/** "← Précédent" / "Suivant →": loaded as soon as the pointer rests on it, so it opens at once. */
function Step({ to, children }: { to: string | null; children: React.ReactNode }) {
  const cls = "flex h-10 items-center rounded-full px-3 text-sm";
  if (!to) return <span aria-disabled="true" className={`${cls} text-muted/50`}>{children}</span>;
  return <LeaveLink href={`/admin/produits/${to}`} intent className={`${cls} text-sea hover:bg-sea-soft`}>{children}</LeaveLink>;
}

export default function ProductEditPage({ name, slug, active, prev, next, editor }: Props) {
  const isNew = editor.id == null;
  return (
    <div className="flex flex-col gap-4">
      <div>
        <div className="flex items-center justify-between gap-2">
          <LeaveLink href="/admin/produits" className="-ms-1 flex h-10 items-center px-1 text-sm text-muted">← Produits</LeaveLink>
          {!isNew && (
            <nav aria-label="Autres produits" className="-me-3 flex items-center">
              <Step to={prev}>← Précédent</Step>
              <Step to={next}>Suivant →</Step>
            </nav>
          )}
        </div>
        <div className="flex min-h-10 flex-wrap items-center gap-x-3">
          <h1 className="min-w-0 break-words font-display text-[26px] leading-tight">{name ?? "Nouveau produit"}</h1>
          {active && slug && (
            <a href={`/produit/${slug}`} target="_blank" className="flex h-10 items-center text-sm text-sea underline">Voir sur la boutique</a>
          )}
        </div>
      </div>
      <ProductEditor {...editor} />
    </div>
  );
}
