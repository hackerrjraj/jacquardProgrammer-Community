"use client";

import Image from "next/image";
import { useCallback, useEffect, useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button, ButtonLink } from "@/components/ui/button";
import { useImageCrop } from "@/components/ui/image-crop-upload";
import { slotForSize } from "@/lib/image-slots";
import { Card } from "@/components/ui/card";
import { Modal } from "@/components/ui/modal";
import { failureText, fieldErrors } from "@/components/profile/profile-shared";
import { apiFetch } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { revalidateSiteContent } from "@/lib/revalidate-site-content";
import { HeroOverlaySetting } from "./hero-overlay-setting";

/** GET /api/admin/site-images — one editable image. url = null means the built-in design is used. */
type Item = {
  key: string;
  group: "brand" | "about" | "headers";
  recommended: string;
  format: string;
  max_kb: number;
  url: string | null;
};
type Flash = { tone: "success" | "danger"; text: string } | null;

const GROUPS = ["brand", "about", "headers"] as const;
const ACCEPTED = ["image/jpeg", "image/png", "image/webp"];

/** Page each image shows on (the "View page" link). */
const VIEW_PATH: Record<string, string> = {
  logo: "/", favicon: "/", og_image: "/", default_avatar: "/programmers", about_image: "/about", about_banner: "/about",
  header_programmers: "/programmers", header_gallery: "/gallery", header_notices: "/notices", header_about: "/about", header_contact: "/contact",
};

/** Site Content → Images tab (part 2): preview, upload, recommended size, remove / use default. */
export function SiteImagesPanel() {
  const t = useDictionary();
  const locale = useLocale();
  const s = t.siteContent;
  const im = s.images;
  const [items, setItems] = useState<Item[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [busyKey, setBusyKey] = useState<string | null>(null);
  const [flash, setFlash] = useState<Flash>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [removing, setRemoving] = useState<Item | null>(null);
  const crop = useImageCrop();
  const inputs = useRef<Record<string, HTMLInputElement | null>>({});

  const load = useCallback(async () => {
    const res = await apiFetch<{ data: Item[] }>("/admin/site-images");
    if (res.ok) {
      setItems(res.data.data);
      setFailed(false);
    } else {
      setFailed(true);
    }
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial data load
    void load();
  }, [load]);

  async function upload(item: Item, file: File | undefined) {
    if (!file) return;
    setFlash(null);
    setErrors((prev) => ({ ...prev, [item.key]: "" }));
    // Checked in the browser first (the API checks again): type and size.
    if (!ACCEPTED.includes(file.type)) {
      setErrors((prev) => ({ ...prev, [item.key]: im.wrongType }));
      return;
    }
    if (file.size > item.max_kb * 1024) {
      setErrors((prev) => ({ ...prev, [item.key]: im.tooBig }));
      return;
    }
    setBusyKey(item.key);
    const body = new FormData();
    body.append("image", file);
    const res = await apiFetch<{ message: string }>(`/admin/site-images/${item.key}`, { method: "POST", body });
    if (res.ok) {
      void revalidateSiteContent();
      await load();
      setFlash({ tone: "success", text: res.data.message });
    } else {
      const apiErrors = fieldErrors(res);
      if (apiErrors.image) setErrors((prev) => ({ ...prev, [item.key]: apiErrors.image }));
      else setFlash({ tone: "danger", text: failureText(res, t) });
    }
    setBusyKey(null);
  }

  async function confirmRemove() {
    if (!removing) return;
    setBusyKey(removing.key);
    const res = await apiFetch<{ message: string }>(`/admin/site-images/${removing.key}`, { method: "DELETE" });
    if (res.ok) {
      void revalidateSiteContent();
      await load();
      setFlash({ tone: "success", text: res.data.message });
    } else {
      setFlash({ tone: "danger", text: failureText(res, t) });
    }
    setBusyKey(null);
    setRemoving(null);
  }

  if (failed && !items) {
    return (
      <Card className="p-5 text-center sm:p-6">
        <p className="text-danger">{s.loadFailed}</p>
        <Button variant="outline" className="mt-4" onClick={() => void load()}>
          {s.retry}
        </Button>
      </Card>
    );
  }
  if (!items) {
    return (
      <Card className="p-5 sm:p-6" aria-busy="true">
        <p role="status" className="text-sm text-muted">
          {s.loading}
        </p>
        <div className="mt-4 h-40 animate-pulse rounded-card bg-border/60" />
      </Card>
    );
  }

  const groupNames = { brand: im.groupBrand, about: im.groupAbout, headers: im.groupHeaders };
  const names = im.items as Record<string, { title: string; hint: string }>;

  return (
    <div className="space-y-6">
      {crop.dialog}
      <Card className="flex flex-wrap items-center justify-between gap-3 p-4 sm:p-5">
        <div className="max-w-3xl space-y-1 text-sm text-muted">
          <p>{im.note}</p>
          <p>{im.heroNote}</p>
        </div>
        <ButtonLink href="/dashboard/hero-slides" variant="outline" size="sm">
          {s.openHero}
        </ButtonLink>
      </Card>

      <HeroOverlaySetting />

      {flash && <Alert tone={flash.tone}>{flash.text}</Alert>}

      {GROUPS.map((group) => (
        <section key={group} className="space-y-4">
          <h2 className="text-lg font-semibold">{groupNames[group]}</h2>
          <ul className="grid gap-4 lg:grid-cols-2">
            {items
              .filter((i) => i.group === group)
              .map((item) => {
                const name = names[item.key];
                const busy = busyKey === item.key;
                const [w, h] = item.recommended.split("×").map((n) => Number(n.trim()));
                const size = localizeDigits(item.recommended, locale);
                return (
                  <li key={item.key}>
                    <Card className="flex h-full flex-col gap-4 p-4 sm:p-5">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-base font-semibold">
                          {name?.title ?? item.key}
                          <span
                            className={`ms-2 rounded-full px-2 py-0.5 text-xs font-medium ${item.url ? "bg-primary-soft text-primary-text" : "bg-border/60 text-muted"}`}
                          >
                            {item.url ? im.customImage : im.usingDefault}
                          </span>
                        </h3>
                      </div>
                      {name && <p className="text-sm text-muted">{name.hint}</p>}

                      <div
                        className="relative mx-auto w-full max-w-sm overflow-hidden rounded-control border border-border bg-[repeating-conic-gradient(var(--color-border)_0_25%,transparent_0_50%)] bg-[length:16px_16px]"
                        style={{ aspectRatio: `${w || 4} / ${h || 3}` }}
                      >
                        {item.url ? (
                          <Image src={item.url} alt={im.previewAlt} fill sizes="400px" className="object-contain" unoptimized />
                        ) : (
                          <span className="absolute inset-0 flex items-center justify-center bg-surface p-3 text-center text-sm text-muted">
                            {im.usingDefault}
                          </span>
                        )}
                      </div>

                      <p className="text-sm">{fill(im.recommended, { size })}</p>
                      {errors[item.key] && <p className="text-sm text-danger">{errors[item.key]}</p>}

                      <div className="mt-auto flex flex-wrap gap-2">
                        <input
                          ref={(el) => {
                            inputs.current[item.key] = el;
                          }}
                          id={`si-${item.key}`}
                          type="file"
                          accept={ACCEPTED.join(",")}
                          className="sr-only"
                          tabIndex={-1}
                          onChange={(e) => {
                            // Crop at the recommended size / shape first (favicon, logo, banners …).
                            const chosen = e.target.files?.[0];
                            if (chosen) crop.pick(chosen, w && h ? slotForSize(w, h) : "logo", (f) => void upload(item, f));
                            e.target.value = "";
                          }}
                        />
                        <Button size="sm" disabled={busy} onClick={() => inputs.current[item.key]?.click()}>
                          {busy ? im.uploading : item.url ? im.replace : im.upload}
                        </Button>
                        <Button size="sm" variant="outline" disabled={busy || !item.url} onClick={() => setRemoving(item)}>
                          {im.remove}
                        </Button>
                        <ButtonLink href={VIEW_PATH[item.key] ?? "/"} variant="ghost" size="sm" target="_blank" rel="noopener">
                          {s.viewPage}
                        </ButtonLink>
                      </div>
                    </Card>
                  </li>
                );
              })}
          </ul>
        </section>
      ))}

      {removing && (
        <Modal title={im.remove} onClose={() => busyKey === null && setRemoving(null)}>
          <p>{im.removeConfirm}</p>
          <p className="mt-2 text-sm text-muted">{names[removing.key]?.title}</p>
          <div className="mt-5 flex justify-end gap-2">
            <Button variant="outline" disabled={busyKey !== null} onClick={() => setRemoving(null)}>
              {t.admin.cancel}
            </Button>
            <Button disabled={busyKey !== null} onClick={confirmRemove}>
              {im.remove}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  );
}
