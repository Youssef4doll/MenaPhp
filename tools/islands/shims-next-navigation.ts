// next/navigation for the PHP shop: every page is a full page load. In the back office,
// router.refresh() draws the page again from the server without reloading it, as Next.js does.
import { refreshPage } from "./admin/actions";

const inAdmin = () => location.pathname === "/admin" || location.pathname.startsWith("/admin/");

export function useRouter() {
  return {
    push: (url: string) => location.assign(url),
    replace: (url: string) => location.replace(url),
    refresh: () => (inAdmin() ? void refreshPage() : location.reload()),
    back: () => history.back(),
    forward: () => history.forward(),
    prefetch: () => {},
  };
}
export function usePathname() {
  return location.pathname;
}
export function useSearchParams() {
  return new URLSearchParams(location.search);
}
export function notFound(): never {
  throw new Error("not found");
}
export function redirect(url: string): never {
  location.assign(url);
  throw new Error("redirect");
}
/** Next.js rethrows its own redirect and not-found errors here; the PHP version has none. */
export function unstable_rethrow(_e: unknown) {}
