/**
 * Image upload slots: crop shape + output size for every image field (one place to change them).
 * Fixed slots: the cropped picture is resized to exactly width × height.
 * Free slots (documents, receipts): any shape, only scaled down to `maxWidth`.
 */
export type ImageSlot = {
  /** width / height; null = free crop */
  aspect: number | null;
  /** Output size (fixed slots) */
  width?: number;
  height?: number;
  /** Longest allowed width (free slots) */
  maxWidth?: number;
  /** webp for pictures shown on the site; jpeg for documents (all document endpoints accept jpg/png) */
  format: "webp" | "jpeg";
  /** Compressed result must not exceed this many bytes */
  maxBytes: number;
};

const KB = 1024;
const fixed = (width: number, height: number, format: ImageSlot["format"] = "webp"): ImageSlot => ({
  aspect: width / height,
  width,
  height,
  format,
  maxBytes: 500 * KB,
});
const free: ImageSlot = { aspect: null, maxWidth: 1600, format: "jpeg", maxBytes: 500 * KB };

export const IMAGE_SLOTS = {
  profilePhoto: fixed(600, 600),
  portfolio: fixed(1200, 900), // 4:3 cards
  gallery: fixed(1200, 900), // 4:3 grid
  heroSlide: fixed(1920, 1080), // 16:9
  expertiseCard: fixed(1200, 675), // 16:9 card image
  committeePhoto: fixed(600, 600),
  logo: fixed(400, 400),
  /** NID, certificates, payment receipts / proof, aid documents, chat attachments */
  document: free,
} satisfies Record<string, ImageSlot>;

export type SlotName = keyof typeof IMAGE_SLOTS;

/** Slot for a Site Content image whose recommended size is known ("512 × 512" → width, height). */
export function slotForSize(width: number, height: number): ImageSlot {
  return fixed(width, height);
}
