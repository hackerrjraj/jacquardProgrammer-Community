import type { Metadata, Viewport } from "next";
import { Hind_Siliguri, Inter, Poppins } from "next/font/google";
import { AuthProvider } from "@/components/auth/auth-provider";
import { PwaSetup } from "@/components/pwa/pwa-setup";
import { I18nProvider } from "@/i18n/client";
import { getDictionary, getLocale } from "@/i18n/server";
import { SiteImagesProvider } from "@/components/layout/site-images-provider";
import { getSiteImages } from "@/lib/site-images";
import "./globals.css";

const hindSiliguri = Hind_Siliguri({
  variable: "--font-hind-siliguri",
  subsets: ["bengali", "latin"],
  weight: ["400", "500", "600", "700"],
});

const inter = Inter({
  variable: "--font-inter",
  subsets: ["latin"],
});

// English headings (MASTER_SPEC §8a)
const poppins = Poppins({
  variable: "--font-poppins",
  subsets: ["latin"],
  weight: ["500", "600", "700"],
});

// Every page reads the language cookie, so pages render per request.
export const instant = false;

export async function generateMetadata(): Promise<Metadata> {
  const [t, images] = await Promise.all([getDictionary(), getSiteImages()]);
  // Uploaded favicon / share image (Site Content → Images); the file name changes on every upload, so it busts the browser cache.
  const version = (url?: string) => (url ? `?v=${url.split("/").pop()?.slice(0, 12)}` : "");
  const share = images.og_image ? [images.og_image] : undefined;
  return {
    title: { default: t.site.name, template: `%s | ${t.site.name}` },
    description: t.site.description,
    applicationName: t.site.name,
    // iPhone / iPad "Add to Home Screen" (Phase 10b)
    icons: {
      apple: `/site-icons/apple-touch-icon.png${version(images.favicon)}`,
      ...(images.favicon ? { icon: [{ url: `/site-icons/favicon-32.png${version(images.favicon)}`, sizes: "32x32", type: "image/png" }] } : {}),
    },
    ...(share ? { openGraph: { siteName: t.site.name, images: share }, twitter: { card: "summary_large_image", images: share } } : {}),
    appleWebApp: { capable: true, title: "JPPHAF", statusBarStyle: "default" },
  };
}

export const viewport: Viewport = {
  themeColor: "#021d30",
};

// Header/footer live in the (site) layout; the dashboard has its own shell.
export default async function RootLayout({ children }: LayoutProps<"/">) {
  const [locale, images] = await Promise.all([getLocale(), getSiteImages()]);
  return (
    <html
      lang={locale}
      className={`${hindSiliguri.variable} ${inter.variable} ${poppins.variable} h-full antialiased`}
    >
      <body className="flex min-h-full flex-col">
        <I18nProvider locale={locale}>
          <SiteImagesProvider images={images}>
            <AuthProvider>{children}</AuthProvider>
          </SiteImagesProvider>
          <PwaSetup />
        </I18nProvider>
      </body>
    </html>
  );
}
