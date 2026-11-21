// The Next.js shop's basket (src/lib/client/cart.ts) as it is, telling the PHP shop's own
// script (public/assets/shop.js: the count in the header) each time it changes.
import * as real from "@mena/lib/client/cart";

export type { CartItem, AddResult } from "@mena/lib/client/cart";
export const { useCart, cartCount, itemKey, onCartAdded } = real;

const told = <A extends unknown[], R>(f: (...a: A) => R) => (...a: A): R => {
  const r = f(...a);
  try {
    dispatchEvent(new Event("mena:cart"));
  } catch {}
  return r;
};
export const addToCart = told(real.addToCart);
export const setCartQty = told(real.setCartQty);
export const removeFromCart = told(real.removeFromCart);
export const replaceCart = told(real.replaceCart);
export const clearCart = told(real.clearCart);
export const removeOrdered = told(real.removeOrdered);
