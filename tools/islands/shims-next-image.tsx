// next/image for the PHP shop: the same <img> next/image writes (sizes, srcset, blurred preview),
// with the PHP shop's picture addresses (/media/<id>/<width>.<ext>, made once, then served as files).
import { useState, type CSSProperties, type SyntheticEvent } from "react";

const WIDTHS = [64, 96, 128, 200, 256, 360, 480, 640, 828, 1080, 1280, 1600];
const ext = () => (typeof window !== "undefined" && (window as unknown as { Mena?: { T?: { imgExt?: string } } }).Mena?.T?.imgExt) || "webp";
const url = (src: string, w: number) => {
  const m = /^\/api\/img\/([a-z0-9]+)/.exec(src);
  if (!m) return src;
  const snap = WIDTHS.find((x) => x >= w) ?? WIDTHS[WIDTHS.length - 1];
  return `/media/${m[1]}/${snap}.${ext()}`;
};
function widths(sizes?: string, width?: number) {
  if (sizes) {
    const pct = [...sizes.matchAll(/(^|\s)(1?\d?\d)vw/g)].map((m) => parseInt(m[2], 10));
    if (pct.length) return { list: WIDTHS.filter((s) => s >= 360 * Math.min(...pct) * 0.01), kind: "w" };
    return { list: WIDTHS, kind: "w" };
  }
  if (typeof width !== "number") return { list: [360, 480, 640, 828, 1080, 1280, 1600], kind: "w" };
  return { list: [...new Set([width, width * 2].map((w) => WIDTHS.find((p) => p >= w) ?? WIDTHS[WIDTHS.length - 1]))], kind: "x" };
}
const blurSvg = (blur: string, w?: number, h?: number) =>
  `url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' ${w && h ? `viewBox='0 0 ${w} ${h}'` : ""}%3E%3Cfilter id='b' color-interpolation-filters='sRGB'%3E%3CfeGaussianBlur stdDeviation='20'/%3E%3CfeColorMatrix values='1 0 0 0 0 0 1 0 0 0 0 0 1 0 0 0 0 0 100 -1' result='s'/%3E%3CfeFlood x='0' y='0' width='100%25' height='100%25'/%3E%3CfeComposite operator='out' in='s'/%3E%3CfeComposite in2='SourceGraphic'/%3E%3CfeGaussianBlur stdDeviation='20'/%3E%3C/filter%3E%3Cimage width='100%25' height='100%25' x='0' y='0' preserveAspectRatio='none' style='filter: url(%23b);' href='${blur}'/%3E%3C/svg%3E")`;

type Props = {
  src: string;
  alt: string;
  sizes?: string;
  priority?: boolean;
  fill?: boolean;
  width?: number;
  height?: number;
  className?: string;
  style?: CSSProperties;
  placeholder?: "blur" | "empty";
  blurDataURL?: string;
  draggable?: boolean;
  onLoad?: (e: SyntheticEvent<HTMLImageElement>) => void;
  loading?: "lazy" | "eager";
  fetchPriority?: "high" | "low" | "auto";
};

/** The props next/image would give an <img> (used to preload the first photo). */
export function getImageProps({ src, sizes, fill, width }: { src: string; alt: string; fill?: boolean; sizes?: string; width?: number }) {
  const { list, kind } = widths(sizes, fill ? undefined : width);
  return { props: { src: url(src, list[list.length - 1]), srcSet: list.map((w, i) => `${url(src, w)} ${kind === "w" ? `${w}w` : `${i + 1}x`}`).join(", "), sizes: kind === "w" ? sizes : undefined } };
}

export default function Image({ src, alt, sizes, priority, fill, width, height, className, style, placeholder, blurDataURL, loading, onLoad, ...rest }: Props) {
  // As next/image: the blurred preview goes once the picture is there.
  const [loaded, setLoaded] = useState(false);
  const { list, kind } = widths(sizes, fill ? undefined : width);
  const srcSet = list.map((w, i) => `${url(src, w)} ${kind === "w" ? `${w}w` : `${i + 1}x`}`).join(", ");
  const blur = placeholder === "blur" && blurDataURL && !loaded ? { backgroundSize: "cover", backgroundPosition: "50% 50%", backgroundRepeat: "no-repeat", backgroundImage: blurSvg(blurDataURL, fill ? undefined : width, fill ? undefined : height) } : {};
  const base: CSSProperties = fill ? { position: "absolute", height: "100%", width: "100%", left: 0, top: 0, right: 0, bottom: 0, color: "transparent" } : { color: "transparent" };
  return (
    <img
      alt={alt}
      loading={priority ? undefined : (loading ?? "lazy")}
      width={fill ? undefined : width}
      height={fill ? undefined : height}
      decoding="async"
      data-nimg={fill ? "fill" : "1"}
      className={className}
      style={{ ...base, ...blur, ...style }}
      sizes={kind === "w" ? sizes : undefined}
      srcSet={srcSet}
      src={url(src, list[list.length - 1])}
      onLoad={(e) => {
        setLoaded(true);
        onLoad?.(e);
      }}
      ref={(img) => {
        // Already there before the page's code ran (written by the server): no load event will come.
        if (img?.complete && img.naturalWidth > 0 && !loaded) setLoaded(true);
      }}
      {...rest}
    />
  );
}
