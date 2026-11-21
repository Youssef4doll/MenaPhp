// @vercel/blob/client for the PHP version: the product video goes to the shop's own server
// (public/uploads/videos), in pieces of 4 MB so that no PHP upload limit refuses a long video.
// The Next.js editor calls upload() exactly as it calls Vercel Blob's.
type Options = {
  access: "public";
  handleUploadUrl: string;
  contentType?: string;
  multipart?: boolean;
  onUploadProgress?: (p: { loaded: number; total: number; percentage: number }) => void;
};

const PIECE = 4 * 1024 * 1024;

export async function upload(pathname: string, file: File, opts: Options): Promise<{ url: string; pathname: string; contentType: string }> {
  const total = file.size;
  let token = "";
  let url = "";
  for (let at = 0; at < total || at === 0; at += PIECE) {
    const piece = file.slice(at, Math.min(total, at + PIECE));
    const q = new URLSearchParams({ path: pathname, type: opts.contentType ?? file.type, size: String(total), at: String(at), token });
    const res = await fetch(`${opts.handleUploadUrl}?${q}`, { method: "POST", body: piece, headers: { "content-type": "application/octet-stream" }, cache: "no-store" });
    const body = (await res.json().catch(() => null)) as { token?: string; url?: string; error?: string } | null;
    if (res.status === 401) throw new Error("Session expirée : reconnectez-vous.");
    if (!res.ok || !body) throw new Error(body?.error ?? `Erreur du serveur (${res.status}).`);
    token = body.token ?? token;
    if (body.url) url = body.url;
    const loaded = Math.min(total, at + piece.size);
    opts.onUploadProgress?.({ loaded, total, percentage: total ? (loaded / total) * 100 : 100 });
    if (total === 0) break;
  }
  if (!url) throw new Error("Envoi incomplet.");
  return { url, pathname, contentType: opts.contentType ?? file.type };
}
