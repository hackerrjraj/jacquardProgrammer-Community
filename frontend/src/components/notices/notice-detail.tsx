"use client";

import { usePathname } from "next/navigation";
import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Container } from "@/components/ui/container";
import { Icon } from "@/components/ui/icon";
import { PageHero } from "@/components/ui/page-hero";
import { apiFetch, type Notice } from "@/lib/api";
import { fill, formatDate } from "@/lib/auth";
import { NoticeAttachmentView, NoticeBadges, useIsMember } from "./notice-shared";
import { noticeBody, noticeTitle } from "./notice-text";

/** One notice: hero with the title, then a card with date, badges, full text, attachment and Back. */
export function NoticeDetail({ notice }: { notice: Notice }) {
  const t = useDictionary();
  const locale = useLocale();
  const n = t.notices;
  const title = noticeTitle(notice, locale);

  return (
    <>
      <PageHero overlap eyebrow={t.eyebrows.notices} title={title} />
      <Container className="pb-12 sm:pb-16">
        <Card className="relative mx-auto -mt-16 max-w-3xl p-5 shadow-panel! sm:p-8">
          <div className="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-muted">
            <span className="inline-flex items-center gap-1.5">
              <Icon name="calendar" className="size-4 text-primary-text" />
              <time dateTime={notice.published_at ?? undefined}>{fill(n.publishedOn, { date: formatDate(notice.published_at, locale) })}</time>
            </span>
            <NoticeBadges notice={notice} t={t} />
          </div>

          <div className="mt-5 text-base leading-relaxed break-words whitespace-pre-line text-text">{noticeBody(notice, locale)}</div>

          {notice.attachment && (
            <div className="mt-6 border-t border-border pt-6">
              <h2 className="mb-3 text-base font-semibold">{n.attachment}</h2>
              <NoticeAttachmentView attachment={notice.attachment} title={title} />
            </div>
          )}

          <div className="mt-8">
            <ButtonLink href="/notices" variant="outline">
              <Icon name="arrowLeft" className="size-4" />
              {n.back}
            </ButtonLink>
          </div>
        </Card>
      </Container>
    </>
  );
}

function NotFoundCard() {
  const t = useDictionary();
  const n = t.notices;
  return (
    <>
      <PageHero overlap eyebrow={t.eyebrows.notices} title={n.notFoundTitle} />
      <Container className="pb-12 sm:pb-16">
        <Card className="relative mx-auto -mt-16 max-w-lg p-8 text-center shadow-panel!">
          <Icon name="megaphone" className="mx-auto size-10 text-primary-text" />
          <p className="mt-3">{n.notFoundText}</p>
          <ButtonLink href="/notices" variant="primary" className="mt-6">
            <Icon name="arrowLeft" className="size-4" />
            {n.back}
          </ButtonLink>
        </Card>
      </Container>
    </>
  );
}

/**
 * Body of the /notices/{id} 404 page. The server asks the public API (no login cookie), so a "members only"
 * notice is a 404 there; a logged-in approved member's browser asks the member API once more and shows it.
 * Everyone else sees "Notice not found".
 */
export function NoticeNotFound() {
  const t = useDictionary();
  const pathname = usePathname();
  const id = pathname.match(/^\/notices\/(\d{1,10})\/?$/)?.[1] ?? null;
  const { loading, member } = useIsMember();
  const [result, setResult] = useState<{ id: string; notice: Notice | null } | null>(null);

  useEffect(() => {
    if (loading || !member || !id) return;
    let cancelled = false;
    void apiFetch<{ data: Notice }>(`/notices/${id}`).then((res) => {
      if (!cancelled) setResult({ id, notice: res.ok ? res.data.data : null });
    });
    return () => {
      cancelled = true;
    };
  }, [loading, member, id]);

  // The server set the "not found" <title>; show the notice's own title once it is found.
  const locale = useLocale();
  const found = result?.id === id ? result.notice : null;
  useEffect(() => {
    if (found) document.title = `${noticeTitle(found, locale)} | ${t.site.name}`;
  }, [found, locale, t.site.name]);

  const checking = loading || (member && !!id && result?.id !== id);
  if (checking)
    return (
      <>
        <PageHero overlap eyebrow={t.eyebrows.notices} title={t.notices.loading} />
        <Container className="pb-12 sm:pb-16">
          <div role="status" className="relative mx-auto -mt-16 h-64 max-w-3xl animate-pulse rounded-card bg-border" aria-label={t.notices.loading} />
        </Container>
      </>
    );
  if (found) return <NoticeDetail notice={found} />;
  return <NotFoundCard />;
}
