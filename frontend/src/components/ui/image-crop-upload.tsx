"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Cropper, { type Area } from "react-easy-crop";
import ReactCrop, { type PercentCrop, type PixelCrop } from "react-image-crop";
import "react-image-crop/dist/ReactCrop.css";
import { useDictionary } from "@/i18n/client";
import { IMAGE_SLOTS, type ImageSlot, type SlotName } from "@/lib/image-slots";
import { Alert } from "./alert";
import { Button } from "./button";
import { Modal } from "./modal";

const CROPPABLE = ["image/jpeg", "image/png", "image/webp"];

type Region = { x: number; y: number; width: number; height: number };

function loadImage(url: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error("load"));
    img.src = url;
  });
}

function toBlob(canvas: HTMLCanvasElement, type: string, quality: number): Promise<Blob | null> {
  return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

/** Crop `region` (source pixels), resize to the slot's size, compress to WebP/JPEG (~80 %, within the slot's byte limit). */
export async function cropToFile(img: HTMLImageElement, region: Region, slot: ImageSlot, name: string): Promise<File | null> {
  let outW: number;
  let outH: number;
  if (slot.width && slot.height) {
    outW = slot.width;
    outH = slot.height;
  } else {
    const scale = Math.min(1, (slot.maxWidth ?? 1600) / region.width);
    outW = Math.max(1, Math.round(region.width * scale));
    outH = Math.max(1, Math.round(region.height * scale));
  }

  let mime = slot.format === "webp" ? "image/webp" : "image/jpeg";
  let quality = 0.8;
  let factor = 1;
  for (let attempt = 0; attempt < 12; attempt++) {
    const canvas = document.createElement("canvas");
    canvas.width = Math.max(1, Math.round(outW * factor));
    canvas.height = Math.max(1, Math.round(outH * factor));
    const ctx = canvas.getContext("2d");
    if (!ctx) return null;
    ctx.imageSmoothingQuality = "high";
    if (mime === "image/jpeg") {
      ctx.fillStyle = "#fff"; // JPEG has no transparency
      ctx.fillRect(0, 0, canvas.width, canvas.height);
    }
    ctx.drawImage(img, region.x, region.y, region.width, region.height, 0, 0, canvas.width, canvas.height);
    let blob = await toBlob(canvas, mime, quality);
    if (blob && blob.type !== mime) {
      // Browser cannot encode WebP → JPEG instead.
      mime = "image/jpeg";
      blob = await toBlob(canvas, mime, quality);
    }
    if (!blob) return null;
    if (blob.size <= slot.maxBytes) {
      const ext = mime === "image/webp" ? "webp" : "jpg";
      const base = name.replace(/\.[^.]+$/, "") || "image";
      return new File([blob], `${base}.${ext}`, { type: mime });
    }
    if (quality > 0.55) quality -= 0.1;
    else factor *= 0.85;
  }
  return null;
}

/** The crop box (modal). Fixed slots: drag + zoom at the slot's ratio. Free slots: drag the box edges. */
function CropDialog({
  file,
  slot,
  onDone,
  onCancel,
}: {
  file: File;
  slot: ImageSlot;
  onDone: (cropped: File) => void;
  onCancel: () => void;
}) {
  const t = useDictionary().imageCrop;
  const [url] = useState(() => URL.createObjectURL(file));
  const [crop, setCrop] = useState({ x: 0, y: 0 });
  const [zoom, setZoom] = useState(1);
  const [area, setArea] = useState<Area | null>(null);
  const [freeCrop, setFreeCrop] = useState<PercentCrop>({ unit: "%", x: 0, y: 0, width: 100, height: 100 });
  const [freePixels, setFreePixels] = useState<PixelCrop | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const freeImg = useRef<HTMLImageElement>(null);

  useEffect(() => () => URL.revokeObjectURL(url), [url]);

  const onComplete = useCallback((_: Area, pixels: Area) => setArea(pixels), []);

  async function confirm() {
    setBusy(true);
    setError(null);
    try {
      const img = await loadImage(url);
      let region: Region;
      if (slot.aspect !== null) {
        if (!area) return setBusy(false);
        region = area;
      } else {
        const shown = freeImg.current;
        const k = shown && shown.width ? img.naturalWidth / shown.width : 1;
        region =
          freePixels && freePixels.width > 4 && freePixels.height > 4
            ? { x: freePixels.x * k, y: freePixels.y * k, width: freePixels.width * k, height: freePixels.height * k }
            : { x: 0, y: 0, width: img.naturalWidth, height: img.naturalHeight };
      }
      const out = await cropToFile(img, region, slot, file.name);
      if (!out) setError(t.tooBig);
      else onDone(out);
    } catch {
      setError(t.readError);
    }
    setBusy(false);
  }

  return (
    <Modal title={t.title} onClose={busy ? () => {} : onCancel}>
      <div className="space-y-3">
        <p className="text-sm text-muted">{t.hint}</p>
        {slot.aspect !== null ? (
          <>
            <div className="relative h-72 w-full overflow-hidden rounded-card bg-black sm:h-80">
              <Cropper
                image={url}
                crop={crop}
                zoom={zoom}
                aspect={slot.aspect}
                minZoom={1}
                maxZoom={4}
                onCropChange={setCrop}
                onZoomChange={setZoom}
                onCropComplete={onComplete}
              />
            </div>
            <label className="flex items-center gap-3 text-sm">
              <span className="shrink-0">{t.zoom}</span>
              <input
                type="range"
                min={1}
                max={4}
                step={0.05}
                value={zoom}
                onChange={(e) => setZoom(Number(e.target.value))}
                className="w-full"
              />
            </label>
          </>
        ) : (
          <div className="flex max-h-80 justify-center overflow-auto rounded-card bg-black/5">
            <ReactCrop crop={freeCrop} onChange={(_, pct) => setFreeCrop(pct)} onComplete={(px) => setFreePixels(px)} keepSelection>
              {/* eslint-disable-next-line @next/next/no-img-element -- local blob preview */}
              <img ref={freeImg} src={url} alt={t.preview} className="max-h-80 w-auto max-w-full" />
            </ReactCrop>
          </div>
        )}
        {error && <Alert tone="danger">{error}</Alert>}
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="outline" onClick={onCancel} disabled={busy}>
            {t.cancel}
          </Button>
          <Button onClick={confirm} disabled={busy}>
            {busy ? t.working : t.confirm}
          </Button>
        </div>
      </div>
    </Modal>
  );
}

type Job = { file: File; slot: ImageSlot; onFile: (f: File) => void };

/**
 * Crop-before-upload for every image field. Use it in place of the field's own file handler:
 *   const crop = useImageCrop();
 *   <input type="file" onChange={(e) => { crop.pick(e.target.files, "profilePhoto", handleFile); e.target.value = ""; }} />
 *   {crop.dialog}
 * Images (JPG/PNG/WebP) open the crop box, then `onFile` gets the cropped, resized, compressed file.
 * Anything else (PDF, …) goes to `onFile` unchanged. Several files are cropped one after another.
 * Cancel skips that file (`onFile` is not called).
 */
export function useImageCrop() {
  const [queue, setQueue] = useState<Job[]>([]);

  const pick = useCallback((files: FileList | File[] | File | null | undefined, slot: SlotName | ImageSlot, onFile: (f: File) => void) => {
    const list = !files ? [] : files instanceof File ? [files] : Array.from(files);
    const resolved = typeof slot === "string" ? IMAGE_SLOTS[slot] : slot;
    const jobs: Job[] = [];
    for (const file of list) {
      if (CROPPABLE.includes(file.type)) jobs.push({ file, slot: resolved, onFile });
      else onFile(file);
    }
    if (jobs.length) setQueue((q) => [...q, ...jobs]);
  }, []);

  const current = queue[0];
  const next = () => setQueue((q) => q.slice(1));

  const dialog = current ? (
    <CropDialog
      key={`${current.file.name}-${current.file.size}-${current.file.lastModified}-${queue.length}`}
      file={current.file}
      slot={current.slot}
      onCancel={next}
      onDone={(cropped) => {
        current.onFile(cropped);
        next();
      }}
    />
  ) : null;

  return { pick, dialog };
}
