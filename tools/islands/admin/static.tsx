// Built and run once by build.mjs: the back office's loading outlines as plain HTML, so that PHP
// shows them at once in the menu, before the page's code has arrived (as Next.js streams them).
import { renderToStaticMarkup } from "react-dom/server";
import PanelLoading from "@/app/admin/(panel)/loading";
import { EditorSkeleton } from "@/app/admin/(panel)/produits/[id]/EditorSkeleton";

export const skeletons = {
  panel: renderToStaticMarkup(<PanelLoading />),
  editor: renderToStaticMarkup(<EditorSkeleton />),
};
