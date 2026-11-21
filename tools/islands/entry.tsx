// The Next.js shop's own interactive components, for the PHP shop's pages.
import { createRoot, hydrateRoot } from "react-dom/client";
import { ProductView } from "@/components/shop/ProductView";
import { QuickSheet } from "@/components/shop/QuickSheet";
import { CartAdded } from "@/components/shop/CartAdded";
import { CartView } from "@/components/shop/CartView";
import { ThanksPhone } from "@/components/shop/ThanksPhone";
import { PurchasePixel } from "@/components/shop/PurchasePixel";
import { SearchPixel } from "@/components/shop/SearchPixel";
import { TrackView } from "@/components/shop/TrackView";
import { LookupForm } from "@/components/shop/LookupForm";
import { ForgetDevice } from "@/components/shop/ForgetDevice";
import { ReviewForm, ReturnForm } from "@/components/shop/AfterSale";

const registry = { ProductView, QuickSheet, CartAdded, CartView, ThanksPhone, PurchasePixel, SearchPixel, TrackView, LookupForm, ForgetDevice, ReviewForm, ReturnForm } as const;
type Name = keyof typeof registry;

declare global {
  interface Window { MenaIslands?: { mount: (name: Name, el: Element, props: Record<string, unknown>, hydrate?: boolean) => () => void } }
}

window.MenaIslands = {
  /** Draws a component into el; with `hydrate`, takes over the same markup written by the server (PHP). */
  mount(name, el, props, hydrate = false) {
    const C = registry[name] as unknown as (p: Record<string, unknown>) => React.ReactNode;
    if (hydrate) {
      const root = hydrateRoot(el, <C {...props} />, {
        onRecoverableError: (e) => console.warn("[mena] page drawn again in the browser:", e),
      });
      return () => root.unmount();
    }
    const root = createRoot(el);
    root.render(<C {...props} />);
    return () => root.unmount();
  },
};
