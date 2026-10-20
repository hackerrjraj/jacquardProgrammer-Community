import type { Dictionary } from "@/i18n/config";

export type NavKey = keyof Pick<
  Dictionary["nav"],
  "home" | "programmers" | "gallery" | "notices" | "about" | "contact"
>;

export const navLinks: { href: string; key: NavKey }[] = [
  { href: "/", key: "home" },
  { href: "/programmers", key: "programmers" },
  { href: "/gallery", key: "gallery" },
  // MASTER_SPEC §12 menu order: … Gallery, Notices, …
  { href: "/notices", key: "notices" },
  { href: "/about", key: "about" },
  { href: "/contact", key: "contact" },
];

export function isActive(pathname: string, href: string) {
  return href === "/"
    ? pathname === "/"
    : pathname === href || pathname.startsWith(`${href}/`);
}
