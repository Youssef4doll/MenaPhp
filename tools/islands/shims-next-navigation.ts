// next/navigation for the PHP shop: every page is a full page load.
export function useRouter() {
  return {
    push: (url: string) => location.assign(url),
    replace: (url: string) => location.replace(url),
    refresh: () => location.reload(),
    back: () => history.back(),
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
