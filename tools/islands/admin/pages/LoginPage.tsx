// app/admin/connexion/page.tsx
import { Logo } from "@/components/shop/Logo";
import { LoginForm } from "@/app/admin/connexion/LoginForm";

export default function LoginPage() {
  return (
    <div className="flex min-h-dvh items-center justify-center px-4">
      <div className="w-full max-w-sm rounded-3xl border border-line bg-white p-7 shadow-soft">
        <Logo />
        <h1 className="mb-5 mt-4 text-lg font-semibold">Back-office</h1>
        <LoginForm />
      </div>
    </div>
  );
}
