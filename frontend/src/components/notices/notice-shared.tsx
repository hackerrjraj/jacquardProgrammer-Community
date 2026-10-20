"use client";

import Image from "next/image";
import { useEffect, useState } from "react";
import { LOCALE_COOKIE, toLocale, type Dictionary } from "@/i18n/config";
import { useDictionary, useLocale } from "@/i18n/client";
import { useAuth } from "@/components/auth/auth-provider";
import { Button } from "@/components/ui/button";
import { Icon } from "@/components/ui/icon";
import { formatSize } from "@/components/profile/profile-shared";
import { apiDownload, type Notice, type NoticeAttachment } from "@/lib/api";

/** A logged-in, approved member (any role) may read members-only notices through the member API. */
export function useIsMember(): { loading: boolean; member: boolean } {
  const { user, loading } = useAuth();
  return { loading, member: !!user && user.status === "approved" && !user.must_change_password };
}

/** Pinned / Members only badges. */
export function NoticeBadges({ notice, t }: { notice: Pick<Notice, "is_pinned" | "visibility">; t: Dictionary }) {
  if (!notice.is_pinned && notice.visibility !== "members") return null;
  return (
    <span className="flex flex-wrap gap-1.5">
      {notice.is_pinned && (
        <span className="inline-flex items-center gap-1 rounded-full bg-warning-soft px-2.5 py-0.5 text-xs font-semibold text-foreground">
          <Icon name="pin" className="size-3.5" />
          {t.notices.pinned}
        </span>
      )}
      {notice.visibility === "members" && (
        <span className="inline-flex items-center gap-1 rounded-full bg-primary-soft px-2.5 py-0.5 text-xs font-semibold text-primary-text">
          <Icon name="lock" className="size-3.5" />
          {t.notices.membersOnly}
        </span>
      )}
    </span>
  );
}

/** Fetches a login-only file with the session cookie (null on any failure). */
async function fetchPrivate(url: string): Promise<Blob | null> {
  try {
    const lang = toLocale(document.cookie.match(new RegExp(`(?:^|; )${LOCALE_COOKIE}=([^;]*)`))?.[1]);
    const res = await fetch(url, { credentials: "include", cache: "no-store", headers: { "Accept-Language": lang } });
    return res.ok ? await res.blob() : null;
  } catch {
    return null;
  }
}

function usePrivateImage(attachment: NoticeAttachment): string | "failed" | null {
  const [src, setSrc] = useState<string | "failed" | null>(null);
  useEffect(() => {
    if (!attachment.is_private || !attachment.is_image) return;
    let cancelled = false;
    let objectUrl: string | null = null;
    void fetchPrivate(attachment.url).then((blob) => {
      if (cancelled) return;
      if (!blob) return setSrc("failed");
      objectUrl = URL.createObjectURL(blob);
      setSrc(objectUrl);
    });
    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [attachment.url, attachment.is_private, attachment.is_image]);
  return src;
}

/**
 * The attachment on the notice page: a picture is shown inline, a PDF gets "Open PDF" + "Download".
 * Public files are plain links; members-only files are fetched with the login cookie (they are not reachable
 * by a plain URL).
 */
export function NoticeAttachmentView({ attachment, title }: { attachment: NoticeAttachment; title: string }) {
  const t = useDictionary();
  const locale = useLocale();
  const n = t.notices;
  const privateImage = usePrivateImage(attachment);
  const [opening, setOpening] = useState(false);
  const [failed, setFailed] = useState(false);
  const isPdf = attachment.mime_type === "application/pdf";

  async function openPrivate() {
    setFailed(false);
    setOpening(true);
    // Open the tab right away (inside the click) so pop-up blockers allow it, then fill it.
    const tab = window.open("", "_blank");
    const blob = await fetchPrivate(attachment.url);
    setOpening(false);
    if (!blob) {
      tab?.close();
      setFailed(true);
      return;
    }
    const url = URL.createObjectURL(blob);
    if (tab) tab.location.href = url;
    else window.location.href = url;
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  }

  async function download() {
    setFailed(false);
    const status = await apiDownload(attachment.url, attachment.name);
    if (status < 200 || status >= 300) setFailed(true);
  }

  const imageSrc = attachment.is_private ? privateImage : attachment.url;

  return (
    <div className="space-y-3">
      {attachment.is_image &&
        (imageSrc === null ? (
          <div role="status" className="flex aspect-[4/3] w-full animate-pulse items-center justify-center rounded-2xl bg-border text-sm text-muted">
            {n.imageLoading}
          </div>
        ) : imageSrc !== "failed" ? (
          <Image
            src={imageSrc}
            alt={title}
            width={1200}
            height={900}
            unoptimized
            className="h-auto max-h-[80vh] w-auto max-w-full rounded-2xl border border-border object-contain"
          />
        ) : null)}

      <div className="flex flex-wrap items-center gap-3 rounded-control border border-border bg-background px-4 py-3">
        <Icon name="paperclip" className="size-5 shrink-0 text-primary-text" />
        <span className="min-w-0 flex-1 text-sm">
          <span className="block truncate font-medium text-heading">{attachment.name}</span>
          <span className="text-muted">{formatSize(attachment.size, locale)}</span>
        </span>
        {attachment.is_private ? (
          // Login-only file: opened / saved through the API with the session cookie.
          <span className="flex flex-wrap gap-2">
            {!attachment.is_image && (
              <Button variant="outline" size="sm" disabled={opening} onClick={() => void openPrivate()}>
                <Icon name="external" className="size-4" />
                {opening ? n.opening : isPdf ? n.openPdf : n.openFile}
              </Button>
            )}
            <Button variant="outline" size="sm" onClick={() => void download()}>
              <Icon name="download" className="size-4" />
              {n.download}
            </Button>
          </span>
        ) : (
          // Public file: a plain link (the browser shows the PDF / picture and can save it from there).
          <a
            href={attachment.url}
            target="_blank"
            rel="noopener"
            className="inline-flex h-9 items-center gap-2 rounded-full border border-border bg-surface px-4 text-sm font-semibold text-secondary hover:border-primary hover:text-primary-text"
          >
            <Icon name="external" className="size-4" />
            {isPdf ? n.openPdf : n.openFile}
          </a>
        )}
      </div>
      {(failed || imageSrc === "failed") && <p className="text-sm text-danger">{n.fileFailed}</p>}
    </div>
  );
}
