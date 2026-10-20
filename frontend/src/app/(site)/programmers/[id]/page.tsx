import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { cache } from "react";
import { getDictionary, getLocale } from "@/i18n/server";
import { PublicProfile } from "@/components/directory/public-profile";
import { ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Container } from "@/components/ui/container";
import { PageHero } from "@/components/ui/page-hero";
import { apiFetch, type PublicProgrammerProfile } from "@/lib/api";
import { optionName } from "@/lib/format";

type Props = { params: Promise<{ id: string }> };

// Waits for the API on purpose: a real 404 status and a per-programmer <title> need the data first.
export const instant = false;

/** One API call per request, shared by the page and its <title>. Server-side = no login cookie. */
const loadProgrammer = cache(async (id: string) => {
  if (!/^\d{1,10}$/.test(id)) return { ok: false as const, status: 404 };
  const res = await apiFetch<{ data: PublicProgrammerProfile }>(`/public/programmers/${id}`, { locale: await getLocale() });
  return res.ok ? { ok: true as const, programmer: res.data.data } : { ok: false as const, status: res.status };
});

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { id } = await params;
  const t = await getDictionary();
  const res = await loadProgrammer(id);
  if (!res.ok) return { title: res.status === 404 ? t.directory.notFoundTitle : t.pages.programmers };
  const p = res.programmer;
  const locale = await getLocale();
  const description = (p.bio ?? [p.designation, ...p.machines.map((m) => optionName(m, locale))].filter(Boolean).join(" · ")).slice(0, 160);
  return {
    title: p.designation ? `${p.name} — ${p.designation}` : p.name,
    description: description || undefined,
    openGraph: { title: p.name, description: description || undefined, images: p.photo_url ? [p.photo_url] : undefined },
  };
}

/** Public programmer profile (MASTER_SPEC §22, programmer-profile screenshot). */
export default async function Page({ params }: Props) {
  const { id } = await params;
  const res = await loadProgrammer(id);
  if (!res.ok && res.status === 404) notFound();

  if (!res.ok) {
    // API down / rate limited — not a 404.
    const t = await getDictionary();
    return (
      <>
        <PageHero overlap eyebrow={t.eyebrows.programmers} title={t.pages.programmers} />
        <Container className="pb-12 sm:pb-16">
          <Card className="relative mx-auto -mt-16 max-w-lg p-8 text-center shadow-panel!">
            <p className="text-danger">{res.status === 429 ? t.directory.tooMany : t.directory.profileFailed}</p>
            <div className="mt-5 flex flex-wrap justify-center gap-2">
              <ButtonLink href={`/programmers/${id}`} variant="outline">
                {t.directory.retry}
              </ButtonLink>
              <ButtonLink href="/programmers" variant="ghost">
                {t.directory.back}
              </ButtonLink>
            </div>
          </Card>
        </Container>
      </>
    );
  }

  return <PublicProfile initial={res.programmer} />;
}
