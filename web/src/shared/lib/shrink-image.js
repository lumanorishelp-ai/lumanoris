const MAX_BYTES = 5 * 1024 * 1024;

function loadImage(file) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      URL.revokeObjectURL(url);
      resolve(img);
    };
    img.onerror = () => {
      URL.revokeObjectURL(url);
      reject(new Error("decode"));
    };
    img.src = url;
  });
}

function toBlob(canvas, type, quality) {
  return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

export async function shrinkImage(file, maxSide) {
  if (!file || file.type === "image/gif" || typeof document === "undefined") return file;
  let img;
  try {
    img = await loadImage(file);
  } catch {
    return file;
  }
  const scale = Math.min(1, maxSide / Math.max(img.naturalWidth, img.naturalHeight));
  if (scale === 1 && file.size <= 600 * 1024) return file;

  const canvas = document.createElement("canvas");
  canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
  canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
  const ctx = canvas.getContext("2d");
  if (!ctx) return file;
  ctx.imageSmoothingQuality = "high";
  ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

  let flat = null;
  for (const [type, quality] of [["image/webp", 0.86], ["image/jpeg", 0.86], ["image/jpeg", 0.72]]) {
    let source = canvas;
    if (type === "image/jpeg") {
      if (!flat) {
        flat = document.createElement("canvas");
        flat.width = canvas.width;
        flat.height = canvas.height;
        const fctx = flat.getContext("2d");
        fctx.fillStyle = "#111120";
        fctx.fillRect(0, 0, flat.width, flat.height);
        fctx.drawImage(canvas, 0, 0);
      }
      source = flat;
    }
    const blob = await toBlob(source, type, quality);
    if (blob && blob.type === type && blob.size < file.size && blob.size <= MAX_BYTES) {
      const ext = type === "image/webp" ? "webp" : "jpg";
      const base = (file.name || "gorsel").replace(/\.[^.]+$/, "");
      return new File([blob], `${base}.${ext}`, { type, lastModified: Date.now() });
    }
  }
  return file;
}

export const IMAGE_MAX_BYTES = MAX_BYTES;
