// next/link for the PHP shop: a plain link (pages are full page loads there).
import { forwardRef, type AnchorHTMLAttributes } from "react";

type Props = AnchorHTMLAttributes<HTMLAnchorElement> & { href: string | { pathname: string }; prefetch?: boolean | null; replace?: boolean; scroll?: boolean };

const Link = forwardRef<HTMLAnchorElement, Props>(function Link({ href, prefetch: _p, replace, scroll: _s, onClick, ...rest }, ref) {
  const to = typeof href === "string" ? href : href.pathname;
  return (
    <a
      ref={ref}
      href={to}
      onClick={(e) => {
        onClick?.(e);
        if (replace && !e.defaultPrevented && e.button === 0 && !e.metaKey && !e.ctrlKey && !e.shiftKey) {
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
