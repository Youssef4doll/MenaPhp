// Mena: small helpers. The shop works without this file; it only makes choosing smoother.
// Prices shown here are for display: the server prices every order again.
(function () {
  "use strict";

  // Lists that apply themselves (sort, basket quantity).
  document.querySelectorAll("select[data-autosubmit]").forEach(function (s) {
    s.addEventListener("change", function () { s.form && s.form.submit(); });
  });

  // A form that asks before it acts (deleting a product).
  document.querySelectorAll("form[data-confirm]").forEach(function (f) {
    f.addEventListener("submit", function (ev) { if (!window.confirm(f.getAttribute("data-confirm"))) ev.preventDefault(); });
  });

  // Product photos: a thumbnail shows its photo in the big frame.
  document.querySelectorAll("[data-gallery]").forEach(function (g) {
    var main = g.querySelector("[data-main]");
    g.querySelectorAll("[data-thumb]").forEach(function (b) {
      b.addEventListener("click", function () {
        if (!main) return;
        main.src = b.getAttribute("data-src");
        main.srcset = b.getAttribute("data-srcset");
        g.querySelectorAll("[data-thumb]").forEach(function (x) { x.removeAttribute("aria-current"); });
        b.setAttribute("aria-current", "true");
      });
    });
  });

  // One tap sends one order: the button waits for the answer.
  document.querySelectorAll("form[data-product-form], form[data-cart-form]").forEach(function (f) {
    f.addEventListener("submit", function (ev) {
      var by = ev.submitter;
      if (by && by.getAttribute("formaction")) return; // "Add to basket"
      var btn = f.querySelector("[data-submit]");
      if (f.dataset.sending) { ev.preventDefault(); return; }
      f.dataset.sending = "1";
      if (btn) btn.setAttribute("aria-busy", "true");
    });
  });

  // Live totals on the product page.
  var dataEl = document.getElementById("product-data");
  var form = document.querySelector("form[data-product-form]");
  if (!dataEl || !form) return;
  var d;
  try { d = JSON.parse(dataEl.textContent); } catch (e) { return; }

  function money(m) {
    var neg = m < 0; m = Math.abs(m);
    var s = Math.floor(m / 1000) + "," + String(m % 1000).padStart(3, "0");
    return (neg ? "−" : "") + s + (d.lang === "ar" ? " د.ت" : " DT");
  }
  function value(name) {
    var el = form.querySelector('[name="' + name + '"]:checked') || form.querySelector('select[name="' + name + '"]');
    return el ? el.value : null;
  }
  function range(min, max) {
    if (d.lang === "ar") {
      if (max === 0) return "نفس اليوم";
      if (min === max) return "خلال " + max + " " + (max === 1 ? "يوم عمل" : "أيام عمل");
      return "خلال " + min + " إلى " + max + " أيام عمل";
    }
    if (max === 0) return "le jour même";
    if (min === max) return max + " jour" + (max > 1 ? "s" : "") + " ouvré" + (max > 1 ? "s" : "");
    return min + " à " + max + " jours ouvrés";
  }

  function update() {
    var vid = value("variantId");
    var oid = value("offerId");
    var qty = parseInt(value("qty") || "1", 10) || 1;
    var unit = d.price;
    d.variants.forEach(function (v) { if (v.id === vid && v.price !== null) unit = v.price; });
    var line, free = false;
    var offer = null;
    d.offers.forEach(function (o) { if (o.id === oid) offer = o; });
    if (offer) { line = offer.price; free = offer.freeDelivery; } else { line = unit * qty; }
    if (d.fee === 0 || (d.threshold !== null && line >= d.threshold)) free = true;
    var gov = value("governorate");
    var zone = gov ? d.zones[gov] : null;
    var fee = (zone && !zone[2]) ? 0 : (free ? 0 : d.fee);
    var total = line + fee;
    form.querySelectorAll("[data-subtotal]").forEach(function (el) { el.textContent = money(line); });
    form.querySelectorAll("[data-fee]").forEach(function (el) {
      el.innerHTML = "";
      if (free) { var s = document.createElement("span"); s.className = "free"; s.textContent = d.words.free; el.appendChild(s); }
      else el.textContent = money(fee);
    });
    form.querySelectorAll("[data-total]").forEach(function (el) { el.textContent = money(total); });
    var unitEl = document.querySelector("[data-unit-price]");
    if (unitEl) unitEl.textContent = money(unit);
    var exp = form.querySelector("[data-expected]");
    if (exp) exp.value = String(total);
    var delay = form.querySelector("[data-delay]");
    if (delay) delay.textContent = zone && zone[2] ? d.words.deliveryTo.replace("%g", d.govNames[gov] || gov).replace("%r", range(zone[0], zone[1])) : "";
  }
  form.addEventListener("change", update);
  update();
})();
