"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useState } from "react";
import { useDictionary } from "@/i18n/client";
import { useAuth } from "@/components/auth/auth-provider";
import { Button, ButtonLink } from "@/components/ui/button";
import { Container } from "@/components/ui/container";
import { LanguageToggle } from "./language-toggle";
import { Logo } from "./logo";
import { isActive, navLinks } from "./nav-links";

/** Public-site header: dark navy, blends into the page hero (MASTER_SPEC §8a). */
export function Header() {
  const t = useDictionary();
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const close = () => setOpen(false);
  const { user, loading, logout } = useAuth();

  return (
    <header className="sticky top-0 z-40 border-b border-white/10 bg-linear-to-r from-navy to-navy-2 text-white">
      <Container className="flex h-18 items-center justify-between gap-3">
        <Logo lines={[t.site.logoLine1, t.site.logoLine2]} label={t.site.name} />

        <nav aria-label={t.nav.mainMenu} className="hidden xl:block">
          <ul className="flex items-center gap-1">
            {navLinks.map(({ href, key }) => {
              const active = isActive(pathname, href);
              return (
                <li key={href}>
                  <Link
                    href={href}
                    aria-current={active ? "page" : undefined}
                    className={`px-2.5 py-2 text-[0.8rem] font-semibold tracking-(--caps-tracking) whitespace-nowrap uppercase transition-colors 2xl:px-3 ${
                      active ? "text-primary" : "text-white/90 hover:text-primary"
                    }`}
                  >
                    {t.nav[key]}
                  </Link>
                </li>
              );
            })}
          </ul>
        </nav>

        <div className="flex shrink-0 items-center gap-2">
          <LanguageToggle dark />
          {/* Nothing until /api/me answers, so logged-in users never see Login flash by. */}
          {!loading && (
            <div className="hidden items-center gap-2 xl:flex">
              {user ? (
                <>
                  <ButtonLink href="/dashboard" size="sm" title={user.name}>
                    {t.nav.dashboard}
                  </ButtonLink>
                  <Button variant="ghostLight" size="sm" onClick={() => void logout()}>
                    {t.nav.logout}
                  </Button>
                </>
              ) : (
                <>
                  <ButtonLink href="/login" variant="ghostLight" size="sm">
                    {t.nav.login}
                  </ButtonLink>
                  <ButtonLink href="/register" size="sm" className="uppercase">
                    {t.nav.join}
                  </ButtonLink>
                </>
              )}
            </div>
          )}
          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            aria-expanded={open}
            aria-controls="mobile-menu"
            aria-label={open ? t.nav.closeMenu : t.nav.openMenu}
            className="inline-flex size-10 items-center justify-center rounded-full border border-white/25 text-white hover:border-primary hover:text-primary xl:hidden"
          >
            <svg
              aria-hidden="true"
              viewBox="0 0 24 24"
              className="size-5"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
            >
              {open ? (
                <path d="M6 6l12 12M18 6L6 18" />
              ) : (
                <path d="M4 7h16M4 12h16M4 17h16" />
              )}
            </svg>
          </button>
        </div>
      </Container>

      {open && (
        <div id="mobile-menu" className="border-t border-white/10 bg-navy xl:hidden">
          <Container className="py-3">
            <nav aria-label={t.nav.mainMenu}>
              <ul className="flex flex-col">
                {navLinks.map(({ href, key }) => {
                  const active = isActive(pathname, href);
                  return (
                    <li key={href}>
                      <Link
                        href={href}
                        onClick={close}
                        aria-current={active ? "page" : undefined}
                        className={`block rounded-control px-3 py-3 text-base font-medium ${
                          active ? "bg-white/10 text-primary" : "text-white/90 hover:bg-white/5"
                        }`}
                      >
                        {t.nav[key]}
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </nav>
            {!loading && (
              <div className="mt-3 border-t border-white/10 pt-3">
                {user ? (
                  <>
                    <p className="truncate px-3 pb-2 font-medium text-white">{user.name}</p>
                    <div className="grid grid-cols-2 gap-2">
                      <ButtonLink href="/dashboard" onClick={close}>
                        {t.nav.dashboard}
                      </ButtonLink>
                      <Button
                        variant="outlineLight"
                        onClick={() => {
                          close();
                          void logout();
                        }}
                      >
                        {t.nav.logout}
                      </Button>
                    </div>
                  </>
                ) : (
                  <div className="grid grid-cols-2 gap-2">
                    <ButtonLink href="/login" variant="outlineLight" onClick={close}>
                      {t.nav.login}
                    </ButtonLink>
                    <ButtonLink href="/register" onClick={close}>
                      {t.nav.join}
                    </ButtonLink>
                  </div>
                )}
              </div>
            )}
          </Container>
        </div>
      )}
    </header>
  );
}
