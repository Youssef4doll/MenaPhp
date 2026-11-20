// Mena, the storefront in the browser: the Next.js shop's client components
// (Header, RowScroll, HeroBanners, HomeFeed, WhatsAppButton, cart.ts, …)
// ported to plain JavaScript. Pages are complete without it; it adds what
// React did there. Prices shown here are for display: the server prices
// every order again.
(function () {
  "use strict";

  var doc = document;
  var body = doc.body;
  var LANG = body.getAttribute("data-lang") === "ar" ? "ar" : "fr";
  var BASE = body.getAttribute("data-base") || "";
  var T = {};
  try { T = JSON.parse(doc.getElementById("mena-texts").textContent); } catch (e) {}
  var Mena = (window.Mena = window.Mena || {});
  Mena.lang = LANG;
  Mena.T = T;

  // ------------------------------------------------------------ helpers

  /** localStorage that never throws (storage.ts). */
  var store = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} },
    remove: function (k) { try { localStorage.removeItem(k); } catch (e) {} },
  };
  Mena.store = store;

  /** formatTND: "89,900 DT" / "89,900 د.ت". */
  function money(m) {
    var n = (m < 0 ? "−" : "") + Math.floor(Math.abs(m) / 1000) + "," + String(Math.abs(m) % 1000).padStart(3, "0");
    return LANG === "ar" ? n + " د.ت" : n + " DT";
  }
  Mena.money = money;

  /** A shop address in the page's language. */
  function href(path) {
    if (!BASE) return path;
    return path === "/" ? BASE : BASE + path;
  }
  Mena.href = href;

  function pick(fr, ar) { return LANG === "ar" && ar ? ar : fr; }
  Mena.pick = pick;

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; });
  }
  Mena.esc = esc;

  function icon(name, cls) { return ((T.icons || {})[name] || "").replace("%CLASS%", cls); }
  Mena.icon = icon;

  function imgUrl(id, w) { return "/media/" + id + "/" + w + "." + (T.imgExt || "webp"); }
  Mena.imgUrl = imgUrl;

  function on(el, ev, fn, opt) { if (el) el.addEventListener(ev, fn, opt); }

  // ---------------------------------------------------- analytics (track.ts)

  var queue = [];
  var timer = null;
  function visitorId() {
    var v = store.get("mena_vid");
    if (!v) {
      v = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 12);
      store.set("mena_vid", v);
    }
    return v;
  }
  function session() {
    var s = null;
    try { s = JSON.parse(sessionStorage.getItem("mena_session") || "null"); } catch (e) {}
    if (!s || typeof s.id !== "string") {
      var p = new URLSearchParams(location.search);
      s = {
        id: (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2, 10),
        touch: { source: p.get("utm_source") || (doc.referrer && doc.referrer.indexOf(location.host) < 0 ? new URL(doc.referrer).hostname : null), medium: p.get("utm_medium"), campaign: p.get("utm_campaign"), content: p.get("utm_content"), landing: location.pathname },
      };
      try { sessionStorage.setItem("mena_session", JSON.stringify(s)); } catch (e) {}
    }
    return s;
  }
  function flush(beacon) {
    if (timer) { clearTimeout(timer); timer = null; }
    if (!queue.length) return;
    var s = session();
    var payload = JSON.stringify({ visitorId: visitorId(), sessionId: s.id, source: s.touch.source, medium: s.touch.medium, campaign: s.touch.campaign, content: s.touch.content, events: queue.splice(0, 20) });
    try {
      if (beacon && navigator.sendBeacon) navigator.sendBeacon("/api/events", new Blob([payload], { type: "application/json" }));
      else fetch("/api/events", { method: "POST", headers: { "content-type": "application/json" }, body: payload, keepalive: true }).catch(function () {});
    } catch (e) {}
    if (queue.length) flush(beacon);
  }
  addEventListener("pagehide", function () { flush(true); });
  doc.addEventListener("visibilitychange", function () { if (doc.visibilityState === "hidden") flush(true); });
  /** First-party analytics: batched, never awaited, never throws. */
  function track(name, data) {
    try {
      session();
      var ev = { name: name, path: location.pathname.slice(0, 200), at: new Date().toISOString() };
      data = data || {};
      if (data.productId) ev.productId = data.productId;
      if (data.value != null) ev.value = data.value;
      if (data.props) ev.props = data.props;
      queue.push(ev);
      if (queue.length >= 10) flush();
      else if (!timer) timer = setTimeout(function () { flush(); }, 1500);
    } catch (e) {}
  }
  Mena.track = track;
  Mena.trackNow = function (name, data) { track(name, data); flush(true); };
  Mena.attribution = function () {
    var s = session();
    return { visitorId: visitorId(), sessionId: s.id, attribution: { source: s.touch.source, medium: s.touch.medium, campaign: s.touch.campaign || undefined, content: s.touch.content || undefined, landing: s.touch.landing } };
  };
  track("page_view");

  // Phone, WhatsApp and Messenger links are counted (ContactLink.tsx).
  on(doc, "click", function (e) {
    var a = e.target.closest && e.target.closest("[data-contact]");
    if (a) track(a.getAttribute("data-contact") + "_clicked", { props: { where: a.getAttribute("data-where") } });
  });
  // Which product was opened from which list (ListClicks.tsx).
  on(doc, "click", function (e) {
    var a = e.target.closest && e.target.closest("[data-slug]");
    var list = a && a.closest("[data-list]");
    if (!a || !list) return;
    var props = { list: list.getAttribute("data-list"), slug: a.getAttribute("data-slug"), position: Number(a.getAttribute("data-pos")) + 1 };
    if (list.getAttribute("data-q")) props.q = list.getAttribute("data-q");
    track("list_item_clicked", { props: props });
  }, true);

  // ------------------------------------------------------------ basket (cart.ts)

  var CART = "mena_cart", MAX_LINES = 30, MAX_QTY = 10;
  var cartCache = null;
  var cartListeners = [];
  function str(v, max) { return typeof v === "string" && v.length <= (max || 200) ? v : null; }
  function cleanCart(raw) {
    if (!Array.isArray(raw)) return [];
    var out = [];
    raw.slice(0, MAX_LINES).forEach(function (o) {
      if (!o || typeof o !== "object") return;
      var productId = str(o.productId, 40), slug = str(o.slug, 140), name = str(o.name, 200);
      if (!productId || !slug || !name) return;
      var offerId = str(o.offerId, 40);
      var qty = typeof o.qty === "number" && Math.floor(o.qty) === o.qty ? Math.min(MAX_QTY, Math.max(1, o.qty)) : 1;
      out.push({ productId: productId, variantId: str(o.variantId, 40), offerId: offerId, qty: offerId ? 1 : qty, slug: slug, name: name, nameAr: str(o.nameAr), variantName: str(o.variantName), variantNameAr: str(o.variantNameAr), offerLabel: str(o.offerLabel), offerLabelAr: str(o.offerLabelAr), imageId: str(o.imageId, 40) });
    });
    return out;
  }
  function readCart() {
    if (cartCache) return cartCache;
    try { cartCache = cleanCart(JSON.parse(store.get(CART) || "[]")); } catch (e) { cartCache = []; }
    return cartCache;
  }
  function writeCart(items) {
    cartCache = items;
    if (items.length) store.set(CART, JSON.stringify(items)); else store.remove(CART);
    cartListeners.forEach(function (l) { l(items); });
  }
  addEventListener("storage", function (e) { if (e.key === CART) { cartCache = null; cartListeners.forEach(function (l) { l(readCart()); }); } });
  var itemKey = function (i) { return i.productId + "|" + (i.variantId || "") + "|" + (i.offerId || ""); };
  var sameChoice = function (a, b) { return a.productId === b.productId && (a.variantId || null) === (b.variantId || null); };
  var addedListeners = [];
  Mena.cart = {
    read: readCart,
    count: function (items) { return (items || readCart()).reduce(function (n, i) { return n + i.qty; }, 0); },
    key: itemKey,
    subscribe: function (l) { cartListeners.push(l); },
    onAdded: function (l) { addedListeners.push(l); },
    /** Same product, option and offer twice: more units (10 at most); an offer stays once; another offer of the same product takes the old one's place. */
    add: function (item) {
      var items = readCart(), key = itemKey(item), result;
      var existing = items.find(function (i) { return itemKey(i) === key; });
      var otherOffer = existing ? null : items.find(function (i) { return sameChoice(i, item) && (i.offerId || item.offerId); });
      if (otherOffer) {
        var next = Object.assign({}, item, { qty: item.offerId ? 1 : Math.min(MAX_QTY, Math.max(1, item.qty)) });
        writeCart(items.map(function (i) { return i === otherOffer ? next : i; }));
        result = "replaced";
      } else if (existing) {
        if (item.offerId || existing.qty >= MAX_QTY) result = "already";
        else {
          writeCart(items.map(function (i) { return itemKey(i) === key ? Object.assign({}, i, { qty: Math.min(MAX_QTY, i.qty + item.qty) }) : i; }));
          result = "more";
        }
      } else if (items.length >= MAX_LINES) result = "full";
      else {
        writeCart(items.concat([Object.assign({}, item, { qty: item.offerId ? 1 : Math.min(MAX_QTY, Math.max(1, item.qty)) })]));
        result = "added";
      }
      addedListeners.forEach(function (l) { l({ item: item, result: result }); });
      return result;
    },
    setQty: function (key, qty) { writeCart(readCart().map(function (i) { return itemKey(i) === key && !i.offerId ? Object.assign({}, i, { qty: Math.min(MAX_QTY, Math.max(1, qty)) }) : i; })); },
    remove: function (key) { writeCart(readCart().filter(function (i) { return itemKey(i) !== key; })); },
    replace: function (items) { writeCart(cleanCart(items)); },
    clear: function () { writeCart([]); },
    removeOrdered: function (lines) {
      var items = readCart();
      var left = items.filter(function (i) { return !lines.some(function (l) { return sameChoice(i, l); }); });
      if (left.length !== items.length) writeCart(left);
    },
  };

  // The basket in the header: the count inside the cart (CartLink).
  function showCount(items) {
    var n = Mena.cart.count(items);
    doc.querySelectorAll("[data-cart-link]").forEach(function (a) {
      var c = a.querySelector("[data-testid=cart-count]");
      if (c) { c.textContent = String(n); c.hidden = n === 0; }
      // cart.open(n): the texts for 0, 1 and 2; from 2 on only the number changes.
      var labels = T.cartOpen || ["", "", ""];
      a.setAttribute("aria-label", n < 2 ? labels[n] : labels[2].replace("2", String(n)));
    });
  }
  showCount(readCart());
  Mena.cart.subscribe(showCount);

  // ------------------------------------------------------------- the menu

  (function () {
    var menu = doc.querySelector("[data-menu]");
    var opener = doc.querySelector("[data-menu-open]");
    if (!menu || !opener) return;
    var overflow = "";
    function onKey(e) { if (e.key === "Escape") close(); }
    function open() {
      overflow = body.style.overflow;
      body.style.overflow = "hidden";
      menu.hidden = false;
      var c = menu.querySelector("button[data-menu-close]:not([tabindex])");
      if (c) c.focus();
      addEventListener("keydown", onKey);
    }
    function close() {
      if (menu.hidden) return;
      menu.hidden = true;
      body.style.overflow = overflow;
      removeEventListener("keydown", onKey);
      opener.focus();
    }
    on(opener, "click", open);
    menu.querySelectorAll("[data-menu-close]").forEach(function (b) { on(b, "click", close); });
    menu.querySelectorAll("a").forEach(function (a) { on(a, "click", close); });
  })();

  // ------------------------------------------------------- search (Header.tsx)

  (function () {
    var wrap = doc.querySelector("[data-search-wrap]");
    var openBtn = doc.querySelector("[data-search-open]");
    // On a product page on a phone, search is an icon until tapped.
    on(openBtn, "click", function () {
      wrap.classList.remove("max-md:hidden");
      openBtn.remove();
      var input = wrap.querySelector("input[name=q]");
      if (input) input.focus();
    });

    var box = doc.querySelector("[data-search]");
    if (!box) return;
    var form = box.querySelector("form");
    var input = box.querySelector("input[name=q]");
    var s = null, open = false, active = -1, items = [], ctrl = null, t = null, lastTerm = "";

    function term() { return input.value.trim(); }

    function completion(text, typed) {
      var n = typed.trim().length;
      var starts = text.slice(0, n).toLocaleLowerCase() === typed.trim().toLocaleLowerCase();
      return starts ? "<span>" + esc(text.slice(0, n)) + "<strong>" + esc(text.slice(n)) + "</strong></span>" : "<strong>" + esc(text) + "</strong>";
    }

    function render() {
      var shown = open && term().length >= 2 ? s : null;
      items = shown
        ? [].concat(
            shown.completions.map(function (c) { return { key: "q:" + c, to: href("/recherche?q=" + encodeURIComponent(c)), kind: "completion", c: c }; }),
            shown.categories.map(function (c) { return { key: "c:" + c.slug, to: href("/categorie/" + c.slug), kind: "category", cat: c }; }),
            shown.products.map(function (p) { return { key: "p:" + p.slug, to: href("/produit/" + p.slug), kind: "product", p: p }; })
          )
        : [];
      var list = box.querySelector("#search-list");
      input.setAttribute("aria-expanded", items.length > 0 ? "true" : "false");
      if (active >= 0 && items[active]) input.setAttribute("aria-activedescendant", "sg-" + active); else input.removeAttribute("aria-activedescendant");
      if (!items.length) { if (list) list.remove(); return; }
      if (!list) {
        list = doc.createElement("ul");
        list.id = "search-list";
        list.setAttribute("role", "listbox");
        list.setAttribute("aria-label", T.suggestions || "");
        list.className = "absolute inset-x-0 top-full z-40 mt-1 max-h-[70vh] overflow-y-auto rounded-2xl border border-line bg-white p-1.5 text-ink shadow-lift";
        list.setAttribute("data-testid", "suggestions");
        box.appendChild(list);
      }
      list.innerHTML = items.map(function (it, i) {
        var cls = "flex min-h-12 items-center gap-3 rounded-lg px-2.5 py-1.5 " + (i === active ? "bg-sand" : "") + " " + (it.kind === "product" && (!items[i - 1] || items[i - 1].kind !== "product") ? "mt-1 border-t border-line pt-2.5" : "");
        var inner;
        if (it.kind === "completion") inner = icon("Search", "h-5 w-5 shrink-0 text-muted") + '<span class="min-w-0 flex-1 truncate text-[17px]" dir="auto">' + completion(it.c, term()) + "</span>";
        else if (it.kind === "category") inner = icon("Chevron", "h-5 w-5 shrink-0 text-sea") + '<span class="flex-1 text-[16px] font-bold text-sea-2">' + esc(pick(it.cat.name, it.cat.nameAr)) + "</span>";
        else inner = '<span class="relative h-12 w-12 shrink-0 overflow-hidden rounded-lg bg-sand">' + (it.p.imageId ? '<img src="' + imgUrl(it.p.imageId, 96) + '" alt="" class="h-full w-full object-cover">' : "") + '</span><span class="min-w-0 flex-1 text-[16px] leading-snug text-ink">' + esc(pick(it.p.name, it.p.nameAr)) + '</span><span class="shrink-0 text-[16px] font-bold price-nums ltr-nums">' + esc(money(it.p.price)) + "</span>";
        return '<li id="sg-' + i + '" role="option" aria-selected="' + (i === active) + '"><a href="' + esc(it.to) + '" data-i="' + i + '" tabindex="-1" class="' + cls + '">' + inner + "</a></li>";
      }).join("");
    }

    function go(i) {
      var it = items[i];
      if (!it) return;
      open = false;
      track("search_suggestion_clicked", { props: { q: term(), kind: it.kind } });
      if (it.kind === "completion") input.value = it.c;
      render();
      location.href = it.to;
    }

    function fetchFor(q) {
      if (q.length < 2 || q === lastTerm) return;
      lastTerm = q;
      if (ctrl) ctrl.abort();
      clearTimeout(t);
      var c = (ctrl = window.AbortController ? new AbortController() : null);
      t = setTimeout(function () {
        fetch("/api/suggest?q=" + encodeURIComponent(q), c ? { signal: c.signal } : {})
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (d) { if (d) { s = d; render(); } })
          .catch(function () {});
      }, 150);
    }

    on(input, "input", function () { open = true; active = -1; fetchFor(term()); render(); });
    on(input, "focus", function () { open = true; render(); });
    on(input, "keydown", function (e) {
      if (!items.length) return;
      if (e.key === "ArrowDown") { e.preventDefault(); active = (active + 1) % items.length; render(); }
      else if (e.key === "ArrowUp") { e.preventDefault(); active = active <= 0 ? items.length - 1 : active - 1; render(); }
      else if (e.key === "Escape") { open = false; render(); }
    });
    on(form, "submit", function (e) {
      e.preventDefault();
      if (active >= 0 && items[active]) return go(active);
      open = false;
      render();
      if (term()) location.href = href("/recherche?q=" + encodeURIComponent(term()));
    });
    on(box, "click", function (e) {
      var a = e.target.closest("a[data-i]");
      if (!a) return;
      e.preventDefault();
      go(Number(a.getAttribute("data-i")));
    });
    on(box, "pointerover", function (e) {
      var a = e.target.closest("a[data-i]");
      if (a && Number(a.getAttribute("data-i")) !== active) { active = Number(a.getAttribute("data-i")); render(); }
    });
    on(doc, "pointerdown", function (e) { if (!box.contains(e.target) && open) { open = false; render(); } });
  })();

  // ------------------------------------------------------- shelves (RowScroll)

  function initRows(root) {
    (root || doc).querySelectorAll("[data-row]").forEach(function (wrap) {
      if (wrap.__row) return;
      wrap.__row = true;
      var row = wrap.firstElementChild;
      var arrows = wrap.querySelectorAll("[data-row-go]");
      function check() { var more = row.scrollWidth > row.clientWidth + 4; arrows.forEach(function (a) { a.hidden = !more; }); }
      if (window.ResizeObserver) new ResizeObserver(check).observe(row);
      arrows.forEach(function (a) {
        on(a, "click", function () {
          var rtl = getComputedStyle(row).direction === "rtl";
          row.scrollBy({ left: Number(a.getAttribute("data-row-go")) * (rtl ? -1 : 1) * row.clientWidth * 0.85, behavior: "smooth" });
        });
      });
    });
  }
  Mena.initRows = initRows;
  initRows();

  // --------------------------------------------------------- HeroBanners.tsx

  doc.querySelectorAll("[data-banners]").forEach(function (root) {
    var ul = root.querySelector("ul");
    var lis = Array.prototype.slice.call(ul.children);
    var dots = root.querySelectorAll("[data-banner-dot]");
    var active = 0, touched = false;
    function mark(n) {
      active = n;
      dots.forEach(function (d, i) {
        d.setAttribute("aria-current", i === n ? "true" : "false");
        d.firstElementChild.className = "block h-2 rounded-full transition-all " + (i === n ? "w-5 bg-ink" : "w-2 bg-ink/25");
      });
    }
    if (window.IntersectionObserver) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) { if (e.isIntersecting) mark(Number(e.target.getAttribute("data-n"))); });
      }, { root: ul, threshold: 0.6 });
      lis.forEach(function (li) { io.observe(li); });
    }
    /** Scrolls the row only (never the page) to banner n; right to left on an Arabic page. */
    function show(n) {
      var li = lis[n];
      if (!li) return;
      var rtl = getComputedStyle(ul).direction === "rtl";
      ul.scrollTo({ left: rtl ? li.offsetLeft - (ul.clientWidth - li.offsetWidth) : li.offsetLeft, behavior: "smooth" });
    }
    function stop() { touched = true; }
    ["pointerdown", "focusin", "wheel"].forEach(function (ev) { on(root, ev, stop, { passive: true }); });
    root.querySelectorAll("[data-banner-go]").forEach(function (b) {
      on(b, "click", function () { stop(); show((active + Number(b.getAttribute("data-banner-go")) + lis.length) % lis.length); });
    });
    dots.forEach(function (d, i) { on(d, "click", function () { stop(); show(i); }); });
    if (!matchMedia("(prefers-reduced-motion: reduce)").matches) {
      setInterval(function () { if (!touched && !doc.hidden) show((active + 1) % lis.length); }, 5000);
    }
  });

  // ------------------------------------------------------ WhatsAppButton.tsx

  (function () {
    var hello = doc.querySelector("[data-wa-hello]");
    if (!hello) return;
    var KEY = "mena_wa_hello";
    function seen() { try { sessionStorage.setItem(KEY, "1"); } catch (e) {} }
    try { if (sessionStorage.getItem(KEY)) return; } catch (e) {}
    function close() { hello.hidden = true; seen(); }
    setTimeout(function () { hello.hidden = false; seen(); }, 3000);
    setTimeout(function () { hello.hidden = true; }, 11000);
    on(hello.querySelector("[data-wa-hello-close]"), "click", close);
    on(hello.querySelector("a"), "click", close);
  })();

  // ------------------------------------------------- opening a product (OpeningHint)

  on(doc, "click", function (e) {
    var a = e.target.closest && e.target.closest("a[data-slug]");
    if (!a || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.button > 0) return;
    var hint = a.querySelector("[data-opening]");
    if (!hint) return;
    setTimeout(function () {
      hint.classList.remove("opacity-0");
      hint.classList.add("opacity-100", "delay-150");
      hint.innerHTML = '<span class="h-9 w-9 animate-spin rounded-full border-[3px] border-sea/25 border-t-sea"></span>';
    }, 0);
  });
  // Back to a page from the browser's cache: no spinner left over.
  addEventListener("pageshow", function () {
    doc.querySelectorAll("[data-opening]").forEach(function (h) { h.classList.remove("opacity-100", "delay-150"); h.classList.add("opacity-0"); h.innerHTML = ""; });
  });

  // ------------------------------------------------------------ HomeFeed.tsx

  /** The products this browser opened, newest first (recent.ts). Never sent anywhere. */
  function readViewed() {
    return (store.get("mena_recent") || "").split(",").filter(function (x) { return /^[a-z0-9]{20,40}$/.test(x); });
  }
  Mena.rememberViewed = function (id) {
    store.set("mena_recent", [id].concat(readViewed().filter(function (x) { return x !== id; })).slice(0, 20).join(","));
  };

  function diversify(ranked) {
    var left = ranked.slice(), out = [];
    while (left.length) {
      var a = out.length ? out[out.length - 1].category : undefined;
      var b = out.length > 1 ? out[out.length - 2].category : undefined;
      var blocked = a != null && a === b ? a : undefined;
      var i = blocked === undefined ? 0 : left.findIndex(function (x) { return x.category !== blocked; });
      out.push(left.splice(i < 0 ? 0 : i, 1)[0]);
    }
    return out;
  }
  /** The visitor's own feed: their categories move up, what they already saw moves down. */
  function personalize(feed, seen) {
    if (!seen.length) return feed;
    var cats = {};
    seen.forEach(function (id) {
      var f = feed.find(function (x) { return x.id === id; });
      if (f && f.category) cats[f.category] = (cats[f.category] || 0) + 1;
    });
    var rankOf = function (f, n) { return n - 4 * Math.min(2, cats[f.category || ""] || 0) + (seen.indexOf(f.id) >= 0 ? 40 : 0); };
    return diversify(feed.map(function (f, n) { return { f: f, r: rankOf(f, n), category: f.category }; }).sort(function (a, b) { return a.r - b.r; })).map(function (x) { return x.f; });
  }

  (function () {
    var more = doc.querySelector("[data-home-more]");
    var dataEl = doc.querySelector("script[data-feed]");
    if (!more || !dataEl) return;
    var feed = [];
    try { feed = JSON.parse(dataEl.textContent).map(function (x) { return { id: x[0], category: x[1] }; }); } catch (e) { return; }
    var tpl = more.querySelector("template[data-feed-cards]");
    var cardOf = {};
    Array.prototype.forEach.call(tpl.content.children, function (c) { cardOf[c.getAttribute("data-card")] = c; });
    var card = function (id) { return cardOf[id] ? cardOf[id].cloneNode(true) : null; };
    var seen = readViewed();

    // HomeForYou: more of the categories looked at, and what was opened last.
    var slot = doc.querySelector("[data-for-you]");
    if (slot && seen.length) {
      var cats = {};
      seen.forEach(function (id) { var f = feed.find(function (x) { return x.id === id; }); if (f && f.category) cats[f.category] = true; });
      var forYou = personalize(feed, seen).filter(function (f) { return f.category && cats[f.category] && seen.indexOf(f.id) < 0; }).slice(0, 10).map(function (f) { return f.id; });
      var recent = seen.filter(function (id) { return cardOf[id]; }).slice(0, 10);
      var html = "";
      if (forYou.length >= 4) html += shelfHtml(T.forYou, rowHtml(forYou, false, "home_for_you"));
      if (recent.length >= 2) html += shelfHtml(T.recent, rowHtml(recent, true, "home_recent"));
      slot.innerHTML = html;
      initRows(slot);
    }
    function shelfHtml(title, inner) {
      return '<section class="mx-auto mt-12 max-w-6xl px-4 md:mt-16"><h2 class="mb-4 text-[22px] font-bold leading-tight text-ink md:text-[26px]">' + esc(title) + "</h2>" + inner + "</section>";
    }
    function rowHtml(ids, compact, list) {
      var div = doc.createElement("div");
      ids.forEach(function (id, i) {
        var c = card(id);
        if (!c) return;
        c.classList.add("snap-start");
        var a = c.querySelector("a[data-pos]");
        if (a) a.setAttribute("data-pos", String(i));
        if (compact) {
          c.querySelectorAll("[data-tag],[data-sold]").forEach(function (x) { x.remove(); });
          var meta = c.querySelector("[data-meta]");
          if (meta && !meta.children.length) meta.remove();
          var buy = c.querySelector("[data-buy]");
          if (buy) { var sp = doc.createElement("span"); sp.setAttribute("aria-hidden", "true"); buy.replaceWith(sp); }
        }
        div.appendChild(c);
      });
      return '<div class="contents" data-list="' + list + '"><div class="relative" data-row><div class="-mx-4 grid auto-cols-[46%] grid-flow-col grid-rows-[repeat(4,auto)] gap-x-3 overflow-x-auto scroll-px-4 px-4 pb-2 snap-x no-scrollbar sm:auto-cols-[30%] md:mx-0 md:auto-cols-[calc((100%-3.75rem)/4)] md:scroll-px-0 md:gap-x-5 md:px-0 lg:auto-cols-[calc((100%-5rem)/5)] xl:auto-cols-[calc((100%-6.25rem)/6)]">' + div.innerHTML + "</div>" + (T.rowArrows || "") + "</div></div>";
    }

    // HomeMore: the shop in the visitor's order, ten more each time the end comes near.
    var ordered = personalize(feed, seen);
    var grid = more.querySelector("[data-list] > div");
    var shown = 10;
    var btn = more.querySelector("[data-more]");
    var all = more.querySelector("[data-all-shop]");
    function fill() {
      grid.innerHTML = "";
      ordered.slice(0, shown).forEach(function (f, i) {
        var c = card(f.id);
        if (!c) return;
        var a = c.querySelector("a[data-pos]");
        if (a) a.setAttribute("data-pos", String(i));
        grid.appendChild(c);
      });
      var left = shown < ordered.length;
      if (btn) btn.hidden = !left;
      if (all) all.hidden = left;
    }
    if (seen.length) fill();
    // As in HomeMore's effect: a new watcher after each step, so an end still in view brings ten more again.
    var end = more.querySelector("[data-more-end]");
    var io = null;
    function watch() {
      if (io) io.disconnect();
      if (!window.IntersectionObserver || !end || shown >= ordered.length) return;
      io = new IntersectionObserver(function (e) { if (e.some(function (x) { return x.isIntersecting; })) step(); }, { rootMargin: "600px 0px" });
      io.observe(end);
    }
    function step() { if (shown < ordered.length) { shown += 10; fill(); watch(); } }
    on(btn, "click", step);
    watch();
  })();
})();
