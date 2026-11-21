// app/admin/(panel)/produits/import/page.tsx
import Link from "next/link";
import { PageTitle } from "@/components/admin/ui";
import { ImportWizard } from "@/app/admin/(panel)/produits/import/ImportWizard";

export default function ImportPage(props: Parameters<typeof ImportWizard>[0]) {
  return (
    <>
      <Link href="/admin/produits" className="-mt-2 mb-1 inline-flex h-10 items-center text-sm text-muted">← Produits</Link>
      <PageTitle title="Importer des produits" sub="Beaucoup de produits d'un coup, depuis Excel ou Google Sheets : textes, prix, photos, catégories, options et offres." />
      <ImportWizard {...props} />
    </>
  );
}
