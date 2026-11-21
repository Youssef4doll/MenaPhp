// next/link for the PHP shop: a plain link (pages are full page loads there).
import { forwardRef, type AnchorHTMLAttributes } from "react";

type NavigateEvent = { preventDefault: () => void };
type Props = AnchorHTMLAttributes<HTMLAnchorElement> & {
  href: string | { pathname: string };
  prefetch?: boolean | null;
  replace?: boolean;
  scroll?: boolean;
  /** As in Next.js: called before a click leaves the page; preventDefault() keeps it. */
  onNavigate?: (e: NavigateEvent) => void;
};

const Link = forwardRef<HTMLAnchorElement, Props>(function Link({ href, prefetch: _p, replace, scroll: _s, onClick, onNavigate, ...rest }, ref) {
  const to = typeof href === "string" ? href : href.pathname;
  return (
    <a
      ref={ref}
      href={to}
      onClick={(e) => {
        onClick?.(e);
        const plain = e.button === 0 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey && (!rest.target || rest.target === "_self");
        if (e.defaultPrevented || !plain) return;
        if (onNavigate) {
          let kept = false;
          onNavigate({ preventDefault: () => void (kept = true) });
          if (kept) return void e.preventDefault();
        }
        if (replace) {
          e.preventDefault();
          location.replace(to);
        }
      }}
      {...rest}
    />
  );
});
export default Link;
export function useLinkStatus() {
  return { pending: false };
}
