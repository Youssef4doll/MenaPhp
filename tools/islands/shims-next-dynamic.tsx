// next/dynamic for the PHP shop: the component is in the same file, loaded at once.
import { lazy, Suspense, type ComponentType } from "react";
export default function dynamic<P extends object>(load: () => Promise<ComponentType<P> | { default: ComponentType<P> }>) {
  const Lazy = lazy(async () => {
    const m = await load();
    return "default" in (m as object) ? (m as { default: ComponentType<P> }) : { default: m as ComponentType<P> };
  });
  return function Dynamic(props: P) {
    return (
      <Suspense fallback={null}>
        <Lazy {...props} />
      </Suspense>
    );
  };
}
