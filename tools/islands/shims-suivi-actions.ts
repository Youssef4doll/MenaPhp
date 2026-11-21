// The Next.js shop's server action "forget this device" (src/app/[lang]/suivi/actions.ts), as a call to the PHP shop.
export async function forgetThisDevice(): Promise<{ ok: true }> {
  await fetch("/api/forget-device", { method: "POST" }).catch(() => {});
  return { ok: true };
}
