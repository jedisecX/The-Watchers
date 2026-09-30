// DOM helpers. This codebase NEVER parses HTML strings or evaluates code (see the banned-sink list in
// scripts/check_security.py). The CSP enforces Trusted Types with no policies ('none'), so any such call
// would throw in supporting browsers anyway. User content only ever reaches the DOM via textContent.

export function h(tag, attrs, ...children) {
  const el = document.createElement(tag);
  if (attrs) {
    for (const [k, v] of Object.entries(attrs)) {
      if (v === undefined || v === null || v === false) continue;
      if (k === "class") el.className = v;
      else if (k === "dataset") Object.assign(el.dataset, v);
      else if (k.startsWith("on") && typeof v === "function") el.addEventListener(k.slice(2), v);
      else if (k === "href") el.setAttribute("href", safeHref(v));
      else if (k === "src") el.setAttribute("src", safeSrc(v));
      else if (k === "value") el.value = v;
      else if (k === "checked" || k === "disabled" || k === "selected" || k === "hidden") el[k] = !!v;
      else if (/^[a-z][a-z0-9-]*$/i.test(k) && !k.toLowerCase().startsWith("on")) el.setAttribute(k, String(v));
    }
  }
  append(el, children);
  return el;
}

function append(el, children) {
  for (const c of children.flat(Infinity)) {
    if (c === null || c === undefined || c === false) continue;
    el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
}

export function clear(el) {
  while (el.firstChild) el.removeChild(el.firstChild);
  return el;
}

export function mount(el, ...children) {
  clear(el);
  append(el, children);
  return el;
}

// Only http(s) absolute URLs or same-origin paths are ever placed in href.
export function safeHref(v) {
  const s = String(v);
  if (s.startsWith("/") && !s.startsWith("//")) return s;
  try {
    const u = new URL(s);
    if (u.protocol === "https:" || u.protocol === "http:") return u.href;
  } catch { /* fallthrough */ }
  return "#";
}

// Media is only ever loaded from our own API.
export function safeSrc(v) {
  const s = String(v);
  if (s.startsWith("/api/v1/media/") || s.startsWith("/assets/") || s.startsWith("blob:")) return s;
  return "";
}

// Render server-produced segments. Every value goes through textContent; links are re-validated here
// even though the server already validated them (defence in depth).
export function renderSegments(segments, nav) {
  const frag = document.createDocumentFragment();
  for (const s of segments || []) {
    if (s.t === "link") {
      const href = safeHref(s.url);
      if (href === "#" || !/^https?:/.test(href)) { frag.append(document.createTextNode(String(s.v))); continue; }
      frag.append(h("a", { href, target: "_blank", rel: "noopener noreferrer nofollow ugc", class: "ext" }, String(s.v)));
    } else if (s.t === "mention" && /^[a-z0-9_]{3,30}$/.test(s.handle)) {
      frag.append(h("a", { href: `/@${s.handle}`, onclick: nav }, String(s.v)));
    } else if (s.t === "tag" && /^[\p{L}\p{N}_]{1,64}$/u.test(s.tag)) {
      frag.append(h("a", { href: `/tag/${encodeURIComponent(s.tag)}`, onclick: nav }, String(s.v)));
    } else {
      frag.append(document.createTextNode(String(s.v ?? "")));
    }
  }
  return frag;
}

export function timeAgo(iso) {
  const d = new Date(iso);
  const s = Math.max(0, (Date.now() - d.getTime()) / 1000);
  if (s < 60) return `${Math.floor(s)}s`;
  if (s < 3600) return `${Math.floor(s / 60)}m`;
  if (s < 86400) return `${Math.floor(s / 3600)}h`;
  if (s < 86400 * 7) return `${Math.floor(s / 86400)}d`;
  return d.toLocaleDateString();
}

let toastTimer;
export function toast(msg, kind = "info") {
  let el = document.getElementById("toast");
  if (!el) { el = h("div", { id: "toast", role: "status", "aria-live": "polite" }); document.body.append(el); }
  el.className = `toast show ${kind}`;
  el.textContent = String(msg);
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { el.className = "toast"; }, 4000);
}

export function avatar(user, size = 40) {
  const initials = (user?.display_name || user?.handle || "?").trim().slice(0, 1).toUpperCase();
  if (user?.avatar_url) {
    return h("img", { class: `avatar s${size}`, src: user.avatar_url, alt: "", width: size, height: size, loading: "lazy" });
  }
  return h("span", { class: `avatar placeholder s${size}`, "aria-hidden": "true" }, initials);
}

export function modal(title, body, actions = []) {
  const dlg = h("dialog", { class: "modal", "aria-label": title },
    h("h2", null, title), body, h("div", { class: "modal-actions" }, actions));
  document.body.append(dlg);
  dlg.addEventListener("close", () => dlg.remove());
  dlg.showModal();
  return dlg;
}

export function field(label, input, hint) {
  return h("label", { class: "field" }, h("span", null, label), input, hint ? h("small", null, hint) : null);
}

export function button(label, onclick, cls = "") {
  return h("button", { type: "button", class: cls, onclick }, label);
}

export function downloadJSON(filename, data) {
  const blob = new Blob([JSON.stringify(data, null, 2)], { type: "application/json" });
  const a = h("a", { href: "#", download: filename });
  a.setAttribute("href", URL.createObjectURL(blob));  // blob: URL of our own serialised data
  document.body.append(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.getAttribute("href")); a.remove(); }, 1000);
}
