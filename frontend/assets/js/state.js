import { h } from "./dom.js";

export const state = { user: null, csrf: null, unread: 0 };

let router = null;
export function setRouter(fn) { router = fn; }

export function navigate(path, { replace = false } = {}) {
  if (!path.startsWith("/") || path.startsWith("//")) path = "/";
  if (replace) history.replaceState(null, "", path); else history.pushState(null, "", path);
  router?.();
}

export function navLink(href, ...children) {
  return h("a", {
    href,
    onclick: (e) => {
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
      e.preventDefault();
      navigate(href);
    },
  }, ...children);
}
