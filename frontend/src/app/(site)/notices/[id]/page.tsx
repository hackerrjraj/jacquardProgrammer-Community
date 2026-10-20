import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { cache } from "react";
import { getDictionary, getLocale } from "@/i18n/server";
import { NoticeDetail } from "@/components/notices/notice-detail";
import { excerpt, noticeBody, noticeTitle } from "@/components/notices/notice-text";
import { ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Container } from "@/components/ui/container";
import { PageHero } from "@/components/ui/page-hero";
import { apiFetch, type Notice } from "@/lib/api";

type Props = { params: Promise<{ id: string }> };

// Waits for the API on purpose: a real 404 status and the notice's <title> need the data first.
export const instant = false;

/** One API call per request, shared by the page and its <title>. Server-side = no login cookie (public notices). */
const loadNotice = cache(async (id: string) => {
  if (!/^\d{1,10}$/.test(id)) return { ok: false as const, status: 404 };
  const res = await apiFetch<{ data: Notice }>(`/public/notices/${id}`, { locale: await getLocale() });
  return res.ok ? { ok: true as const, notice: res.data.data } : { ok: false as const, status: res.status };
});

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { id } = await params;
  const t = await getDictionary();
  const locale = await getLocale();
  const res = await loadNotice(id);
  if (!res.ok) return { title: res.status === 404 ? t.notices.notFoundTitle : t.pages.notices };
  return {
    title: noticeTitle(res.notice, locale),
    description: excerpt(noticeBody(res.notice, locale), 160) || undefined,
  };
}

/** One notice (MASTER_SPEC §24). */
export default async function Page({ params }: Props) {
  const { id } = await params;
  const res = await loadNotice(id);
  if (!res.ok && res.status === 404) notFound();

  if (!res.ok) {
    // API down / rate limited — not a 404.
    const t = await getDictionary();
    return (
      <>
        <PageHero overlap eyebrow={t.eyebrows.notices} title={t.pages.notices} />
        <Container className="pb-12 sm:pb-16">
          <Card className="relative mx-auto -mt-16 max-w-lg p-8 text-center shadow-panel!">
            <p className="text-danger">{res.status === 429 ? t.notices.tooMany : t.notices.detailFailed}</p>
            <div className="mt-5 flex flex-wrap justify-center gap-2">
              <ButtonLink href={`/notices/${id}`} variant="outline">
                {t.notices.retry}
              </ButtonLink>
              <ButtonLink href="/notices" variant="ghost">
                {t.notices.back}
              </ButtonLink>
            </div>
          </Card>
        </Container>
      </>
    );
  }

  return <NoticeDetail notice={res.notice} />;
}
