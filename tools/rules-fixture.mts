// The Next.js shop's own answers for its display rules and search, for the PHP version to match.
import { offersApply, leadOffer, offerCompareAt, discountPct, offerLastDay, formatDay } from "/home/claude/mena/src/lib/offer-display";
import { parseSpecs, parseVideo, soldStep } from "/home/claude/mena/src/lib/product-content";
import { stockState, cardPrice, cardDeal } from "/home/claude/mena/src/lib/catalog";
import { saving, score, diversify, rankFeed, type FeedItem } from "/home/claude/mena/src/lib/home-feed";
import { normalize, tokenize, distance, matchWord, rank, complete, withSynonyms } from "/home/claude/mena/src/lib/search";
import { writeFileSync } from "node:fs";

const NOW = Date.parse("2026-11-20T12:00:00Z");
const utc = (d: Date | null) => (d ? d.toISOString().replace("T", " ").slice(0, 19) : null);
const future = new Date(NOW + 5 * 86400_000), past = new Date(NOW - 86400_000);
const o = (quantity: number, price: number, extra: Record<string, unknown> = {}) => ({ quantity, price, endsAt: null as Date | null, label: "", labelAr: null as string | null, freeDelivery: false, ...extra });
const rows = [
  { price: 49900, compareAt: null, variants: [], offers: [], stockMode: "SUPPLIER", stockQty: 0, available: true },
  { price: 49900, compareAt: 59900, variants: [], offers: [], stockMode: "SUPPLIER", stockQty: 0, available: true },
  { price: 49900, compareAt: null, variants: [{ price: null, stockQty: 3, available: true }, { price: 59900, stockQty: 0, available: true }], offers: [], stockMode: "TRACKED", stockQty: 0, available: true },
  { price: 69000, compareAt: 79000, variants: [], offers: [o(1, 69000), o(2, 123900), o(3, 138000, { freeDelivery: true, label: "Achetez 2, la 3e offerte" })], stockMode: "SUPPLIER", stockQty: 0, available: true },
  { price: 69000, compareAt: null, variants: [], offers: [o(2, 120000), o(3, 150000, { endsAt: future }), o(4, 160000, { endsAt: past })], stockMode: "SUPPLIER", stockQty: 0, available: true },
  { price: 69000, compareAt: null, variants: [], offers: [o(1, 69000, { freeDelivery: true }), o(3, 150000)], stockMode: "TRACKED", stockQty: 2, available: true },
  { price: 69000, compareAt: null, variants: [{ price: 70000, stockQty: 0, available: true }], offers: [o(1, 69000), o(2, 100000)], stockMode: "SUPPLIER", stockQty: 0, available: true },
  { price: 30000, compareAt: null, variants: [{ price: null, stockQty: 0, available: false }], offers: [], stockMode: "SUPPLIER", stockQty: 0, available: true },
  { price: 30000, compareAt: null, variants: [], offers: [], stockMode: "SUPPLIER", stockQty: 0, available: false },
  { price: 30000, compareAt: null, variants: [], offers: [], stockMode: "TRACKED", stockQty: 4, available: true },
  { price: 30000, compareAt: null, variants: [], offers: [], stockMode: "TRACKED", stockQty: 9, available: true },
] as const;
const cards = rows.map((r) => ({ row: { ...r, offers: r.offers.map((x) => ({ ...x, endsAt: utc(x.endsAt) })) }, price: cardPrice(r as never, NOW), deal: cardDeal(r as never, NOW), stock: stockState(r as never) }));
const offerSets = [[o(1, 69000), o(2, 123900), o(3, 138000)], [o(2, 120000), o(3, 150000)], [o(3, 150000), o(2, 130000), o(2, 120000)], []];
const offers = offerSets.map((set) => ({ set, lead: leadOffer(set), compare: set.map((x) => offerCompareAt(set, x, 79000)) }));
const specs = ["Matière : inox\nCouleur: noir", "- Réservoir : 300 ml\n• Arrêt : oui\n\nSans deux points", "الخزان: 300 مل\nالضو：7 ألوان", "Titre :\n: valeur", ...[Array.from({ length: 45 }, (_, i) => `L${i}: ${i}`).join("\n")]];
const videos = ["https://youtu.be/dQw4w9WgXcQ", "https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=3", "https://youtube.com/shorts/dQw4w9WgXcQ", "https://vimeo.com/123456789", "https://player.vimeo.com/video/123456789", "https://www.tiktok.com/@shop/video/7301234567890123456", "https://www.instagram.com/reel/C1a2b3c4d5e/", "https://www.instagram.com/p/C1a2b3c4d5e/", "https://www.facebook.com/page/videos/1234567890/", "https://www.facebook.com/reel/1234567890", "https://www.facebook.com/watch/?v=1234567890", "https://cdn.example.com/v/a.mp4", "https://x.com/s.m3u8", "http://youtu.be/dQw4w9WgXcQ", "not a url", "", "https://vm.tiktok.com/abc"];
const feedCard = (id: number, extra: Record<string, unknown> = {}) => ({ id: `p${id}`, slug: `p${id}`, name: `P${id}`, nameAr: null, price: 50000, priceFrom: false, packOf: null, compareAt: null, images: [{ id: "m", alt: null, width: 1, height: 1, blur: null }], stock: { kind: "in_stock" }, rating: null, sold: null, freeDelivery: false, deal: null, ...extra });
const items: FeedItem[] = Array.from({ length: 14 }, (_, i) => ({
  card: feedCard(i, i === 3 ? { compareAt: 70000 } : i === 5 ? { deal: { label: "", labelAr: null, quantity: 3, price: 120000, freeDelivery: false } } : i === 7 ? { stock: { kind: "out" } } : i === 9 ? { images: [] } : i === 11 ? { packOf: 2, price: 90000 } : {}) as never,
  category: ["a", "a", "a", "b", "a", "c", null, "a", "b", "a", "a", "c", "b", "a"][i],
  featured: i % 4 === 0,
  createdAt: NOW - i * 3 * 86400_000,
  orders: [0, 5, 1, 0, 12, 0, 3, 9, 0, 2, 0, 1, 0, 30][i],
  views: [0, 50, 4, 10, 200, 0, 30, 90, 2, 20, 0, 5, 1, 400][i],
  photos: i % 5, details: i % 3 === 0 ? 1 : 0,
}));
const texts = ["Lampe de Chevet", "لمبة النوم", "مكياج", "Mâquillage", "٣ حبات", "Œuf  &  Bœuf!", "الطاولة", "أإآٱ ة ى ؤ ئ ـ", "Tapis berbère 120×170"];
const pairs: [string, string][] = [["lampe", "lampe"], ["lamp", "lampe"], ["lmape", "lampe"], ["diffuser", "diffuseur"], ["difus", "diffuseur"], ["sac", "bac"], ["makina", "main"], ["kess", "kes"], ["مكياج", "ماكياج"], ["بخار", "بار"], ["طولة", "طاولة"], ["tapi", "tapis"], ["euse", "diffuseur"], ["verres", "verre"]];
const docs = [
  { id: "lampe", fields: [{ tokens: withSynonyms(tokenize("Lampe de chevet tactile لمبة")), weight: 3 }, { tokens: tokenize("veilleuse lamba"), weight: 2.5 }] },
  { id: "diff", fields: [{ tokens: withSynonyms(tokenize("Diffuseur d'huiles essentielles موزع")), weight: 3 }, { tokens: tokenize("parfum maison humidificateur"), weight: 2.5 }] },
  { id: "tapis", fields: [{ tokens: withSynonyms(tokenize("Tapis berbère 120 × 170 زربية")), weight: 3 }] },
  { id: "verres", fields: [{ tokens: withSynonyms(tokenize("Set de 6 verres ambrés")), weight: 3 }, { tokens: tokenize("cuisine"), weight: 1.5 }] },
  { id: "makeup", fields: [{ tokens: withSynonyms(tokenize("Trousse de maquillage")), weight: 3 }] },
];
const queries = ["lampe", "lamba", "lmape", "لمبة", "difus", "diffuseur parfum", "verre", "verres ambres", "zarbia", "مكياج", "tapis berbere noir", "xyz", "la", "lampe noire"];
const phrases = ["Lampe de chevet tactile", "Lampe de bureau", "Diffuseur d'huiles essentielles", "Tapis berbère", "Lampadaire", "لمبة النوم", "Set de 6 verres ambrés", "Lampe de chevet tactile"];
writeFileSync(process.argv[2], JSON.stringify({
  now: NOW, nowUtc: utc(new Date(NOW)),
  cards, offers,
  discount: [[69000, 79000], [69000, null], [69000, 69000], [33333, 100000]].map(([p, c]) => ({ p, c, pct: discountPct(p as number, c as number | null) })),
  lastDay: ["2026-11-20 23:00:00", "2026-11-20 22:59:59", "2026-12-01 00:00:00"].map((e) => ({ e, day: offerLastDay(new Date(e.replace(" ", "T") + "Z")) })),
  days: ["2026-10-15", "2026-01-01"].flatMap((d) => ["fr-TN", "ar-TN"].map((l) => ({ d, l, s: formatDay(d, l) }))),
  specs: specs.map((s) => ({ s, out: parseSpecs(s) })),
  videos: videos.map((v) => ({ v, out: parseVideo(v) })),
  sold: [0, 9, 10, 19, 20, 49, 50, 99, 100, 1500, 6000].map((n) => ({ n, s: soldStep(n) })),
  feed: { items, savings: items.map((i) => saving(i.card)), scores: items.map((i) => score(i, NOW)), ranked: rankFeed(items, NOW).map((i) => i.card.id), diversified: diversify(items).map((i) => i.card.id) },
  search: {
    normalize: texts.map((t) => ({ t, n: normalize(t), k: tokenize(t), syn: withSynonyms(tokenize(t)) })),
    distance: pairs.map(([a, b]) => ({ a, b, d: distance(a, b), d1: distance(a, b, 1) })),
    match: pairs.flatMap(([a, b]) => [true, false].map((last) => ({ a, b, last, m: matchWord(a, b, last) }))),
    rank: queries.flatMap((q) => [true, false].map((prefix) => ({ q, prefix, r: rank(q, docs, { prefix }).map((x) => ({ id: x.id, score: x.score, corrected: Object.fromEntries(x.corrected) })) }))),
    docs,
    complete: ["lam", "lampe ch", "تمبة", "lmp", "di", "verres"].map((q) => ({ q, c: complete(q, phrases, 4) })),
    phrases,
  },
}));
console.log("ok");
