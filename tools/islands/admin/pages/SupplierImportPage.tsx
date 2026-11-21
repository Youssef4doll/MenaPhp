// app/admin/(panel)/produits/fournisseur/page.tsx
import Link from "next/link";
import { PageTitle } from "@/components/admin/ui";
import { SupplierImport } from "@/app/admin/(panel)/produits/fournisseur/SupplierImport";

export default function SupplierImportPage(props: Parameters<typeof SupplierImport>[0]) {
  return (
    <>
      <Link href="/admin/produits" className="-mt-2 mb-1 inline-flex h-10 items-center text-sm text-muted">← Produits</Link>
      <PageTitle title="Ajouter depuis Ecomness" sub="Collez la liste de vos produits Ecomness, ou la page d'un produit : photos en grand, textes en français et en arabe tunisien, coût et référence. Les produits sont créés hors ligne." />
      <SupplierImport {...props} />
    </>
  );
}
