"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { apiFetch, type Notice, type Paginated } from "@/lib/api";
import { fill, formatDate } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { NoticeBadges, useIsMember } from "./notice-shared";
import { excerpt, noticeBody, noticeTitle } from "./notice-text";

type Loaded =
  | { key: string; ok: true; items: Notice[]; page: number; last: number; total: number }
  | { key: string; ok: false; status: number };

/** Grey cards while the notices load (same shape as a notice card). */
export function NoticesSkeleton({ count = 4 }: { count?: number }) {
  return (
    <ul className="space-y-4" aria-busy="true">
      {Array.from({ length: count }, (_, i) => (
        <li key={i} className="rounded-card border border-border bg-surface p-5 shadow-card sm:p-6">
          <div className="h-3 w-28 animate-pulse rounded bg-border" />
          <div className="mt-3 h-5 w-3/4 animate-pulse rounded bg-border" />
          <div className="mt-3 h-3 w-full animate-pulse rounded bg-border/70" />
          <div className="mt-2 h-3 w-2/3 animate-pulse rounded bg-border/70" />
        </li>
      ))}
    </ul>
  );
}

/**
 * Public notice board (MASTER_SPEC §24): pinned first, then newest; 12 per page, page number in the URL (?page=2).
 * A logged-in approved member gets the member API, which also lists "members only" notices (with a badge).
 */
export function PublicNotices() {
  const t = useDictionary();
  const locale = useLocale();
  const n = t.notices;
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const digits = (v: number) => localizeDigits(String(v), locale);

  const rawPage = Number(searchParams.get("page"));
  const page = Number.isInteger(rawPage) && rawPage > 1 && rawPage < 100000 ? rawPage : 1;

  // Wait for the login check, so a member never sees the public list flash first.
  const { loading: authLoading, member } = useIsMember();
  const [reloadKey, setReloadKey] = useState(0);
  const requestKey = `${member}|${page}|${reloadKey}`;
  const [loaded, setLoaded] = useState<Loaded | null>(null);

  useEffect(() => {
    if (authLoading) return;
    let cancelled = false;
    const load = async () => {
      let res = await apiFetch<Paginated<Notice>>(`${member ? "/notices" : "/public/notices"}?page=${page}`);
      // Session ended meanwhile → show the public list instead of an error.
      if (!res.ok && member && (res.status === 401 || res.status === 403))
        res = await apiFetch<Paginated<Notice>>(`/public/notices?page=${page}`);
      if (cancelled) return;
      setLoaded(
        res.ok
          ? { key: requestKey, ok: true, items: res.data.data, page: res.data.meta.current_page, last: res.data.meta.last_page, total: res.data.meta.total }
          : { key: requestKey, ok: false, status: res.status },
      );
    };
    void load();
    return () => {
      cancelled = true;
    };
  }, [authLoading, member, page, requestKey]);
  const current = loaded?.key === requestKey ? loaded : null;

  const goTo = (p: number) => router.push(p > 1 ? `${pathname}?page=${p}` : pathname);

  if (!current)
    return (
      <>
        <p role="status" className="sr-only">
          {n.loading}
        </p>
        <NoticesSkeleton />
      </>
    );

  if (!current.ok)
    return (
      <Card className="mx-auto max-w-lg p-8 text-center">
        <Icon name="alert" className="mx-auto size-8 text-danger" />
        <p className="mt-3 text-danger">{current.status === 429 ? n.tooMany : n.loadFailed}</p>
        <Button variant="outline" className="mt-5" onClick={() => setReloadKey((k) => k + 1)}>
          {n.retry}
        </Button>
      </Card>
    );

  if (current.items.length === 0)
    return (
      <Card className="mx-auto max-w-lg p-8 text-center">
        <Icon name="megaphone" className="mx-auto size-10 text-primary-text" />
        <p className="mt-3 font-heading text-lg font-semibold text-heading">{n.emptyTitle}</p>
        <p className="mt-1 text-muted">{n.emptyText}</p>
        {page > 1 && (
          <Button variant="outline" className="mt-5" onClick={() => goTo(1)}>
            {n.back}
          </Button>
        )}
      </Card>
    );

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-2 text-sm text-muted">
        <p>{fill(n.total, { n: digits(current.total) })}</p>
        {member && <p>{n.memberNote}</p>}
      </div>

      <ul className="space-y-4">
        {current.items.map((notice) => {
          const title = noticeTitle(notice, locale);
          return (
            <li key={notice.id}>
              <Link
                href={`/notices/${notice.id}`}
                className={`group block rounded-card border bg-surface p-5 shadow-card transition hover:-translate-y-0.5 hover:shadow-card-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:p-6 ${
                  notice.is_pinned ? "border-primary/40" : "border-border"
                }`}
              >
                <div className="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-muted">
                  <span className="inline-flex items-center gap-1.5">
                    <Icon name="calendar" className="size-4 text-primary-text" />
                    <time dateTime={notice.published_at ?? undefined}>{formatDate(notice.published_at, locale)}</time>
                  </span>
                  <NoticeBadges notice={notice} t={t} />
                  {notice.attachment && (
                    <span className="inline-flex items-center gap-1" title={notice.attachment.name}>
                      <Icon name="paperclip" className="size-4" />
                      <span className="sr-only sm:not-sr-only">{n.attachment}</span>
                    </span>
                  )}
                </div>
                <h2 className="mt-2 text-lg font-semibold break-words text-heading group-hover:text-primary-text sm:text-xl">{title}</h2>
                <p className="mt-2 line-clamp-3 break-words">{excerpt(noticeBody(notice, locale))}</p>
                <span className="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-primary-text">
                  {n.readMore}
                  <Icon name="chevronRight" className="size-4" />
                </span>
              </Link>
            </li>
          );
        })}
      </ul>

      {current.last > 1 && (
        <nav aria-label={fill(n.page, { page: digits(current.page), last: digits(current.last) })} className="flex flex-wrap items-center justify-center gap-3 pt-2">
          <Button variant="outline" size="sm" disabled={current.page <= 1} onClick={() => goTo(current.page - 1)}>
            <Icon name="chevronLeft" className="size-4" />
            {n.prevPage}
          </Button>
          <span className="text-sm text-muted">{fill(n.page, { page: digits(current.page), last: digits(current.last) })}</span>
          <Button variant="outline" size="sm" disabled={current.page >= current.last} onClick={() => goTo(current.page + 1)}>
            {n.nextPage}
            <Icon name="chevronRight" className="size-4" />
          </Button>
        </nav>
      )}
    </div>
  );
}
