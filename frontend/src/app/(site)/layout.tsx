import { Suspense } from "react";
import { Header } from "@/components/layout/header";
import { Footer } from "@/components/layout/footer";

/** Public website: header + footer around every page. */
export default function SiteLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <Header />
      <main className="flex flex-1 flex-col">{children}</main>
      {/* The footer reads Site Settings from the API — own boundary so pages can still prerender (Phase 12d). */}
      <Suspense fallback={null}>
        <Footer />
      </Suspense>
    </>
  );
}
