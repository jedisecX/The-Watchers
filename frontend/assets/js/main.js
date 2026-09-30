import { get, onReauthRequired, post, setCsrf } from "./api.js";
import { avatar, h, mount, toast } from "./dom.js";
import { reauthDialog } from "./components.js";
import * as rt from "./realtime.js";
import { navigate, navLink, setRouter, state } from "./state.js";
import { loginView, registerView, resetView, verifyEmailView } from "./views/auth.js";
import { exploreView, homeView, searchView, tagView } from "./views/feed.js";
import { profileView } from "./views/profile.js";
import { postView } from "./views/post.js";
import { notificationsView } from "./views/notifications.js";
import { conversationView, inboxView } from "./views/messages.js";
import { settingsView } from "./views/settings.js";

const app = document.getElementById("app");
const main = h("main", { id: "main", tabindex: "-1" });
const nav = h("nav", { class: "side", "aria-label": "Primary" });
const badge = h("span", { class: "count", hidden: true });

onReauthRequired((code) => reauthDialog(code));

function renderNav() {
  const u = state.user;
  mount(nav,
    navLink("/", h("span", { class: "brand" }, "🛡 Bastion")),
    u ? navLink("/", "Home") : null, navLink("/explore", "Explore"), u ? navLink("/search", "Search") : null,
    u ? navLink("/notifications", "Notifications ", badge) : null, u ? navLink("/messages", "Messages") : null,
    u ? navLink(`/@${u.handle}`, "Profile") : null, u ? navLink("/settings", "Settings") : null,
    u && u.role !== "user" ? h("a", { href: "/admin" }, "Moderation") : null,
    u ? h("div", { class: "me" }, avatar(u, 32), h("span", null, `@${u.handle}`),
      h("button", { type: "button", class: "link", onclick: logout }, "Sign out"))
      : h("div", { class: "me" }, navLink("/login", "Sign in"), " · ", navLink("/register", "Sign up")));
}

async function logout() {
  try { await post("/api/v1/auth/logout"); } catch { /* ignore */ }
  rt.stop(); state.user = null; setCsrf(null); renderNav(); navigate("/login");
}

async function refreshUnread() {
  if (!state.user) return;
  try {
    const r = await get("/api/v1/notifications/unread-count");
    state.unread = r.count;
  } catch { /* ignore */ }
  badge.hidden = !state.unread;
  badge.textContent = state.unread > 99 ? "99+" : String(state.unread);
}
document.addEventListener("unread", () => { badge.hidden = !state.unread; });

function bootstrap() {
  renderNav();
  if (state.user) { rt.start(); refreshUnread(); }
}

rt.onEvent((ev) => {
  if (ev.type === "notification") { state.unread += 1; badge.hidden = false; badge.textContent = String(state.unread); }
  else if (ev.type === "message" && !location.pathname.startsWith(`/messages/${ev.conversation_id}`)
           && ev.message?.sender_id !== state.user?.id) toast("New message");
  else if (ev.type === "session_ended") { state.user = null; setCsrf(null); renderNav(); navigate("/login"); toast("You were signed out."); }
});

const routes = [
  [/^\/$/, () => (state.user ? homeView(main) : exploreView(main))],
  [/^\/explore$/, () => exploreView(main)],
  [/^\/login$/, () => loginView(main, bootstrap)],
  [/^\/register$/, () => registerView(main)],
  [/^\/verify-email$/, () => verifyEmailView(main)],
  [/^\/reset-password$/, () => resetView(main)],
  [/^\/search$/, () => searchView(main), true],
  [/^\/tag\/([^/]{1,64})$/, (m) => tagView(main, decodeURIComponent(m[1]))],
  [/^\/@([A-Za-z0-9_]{3,30})(?:\/(replies|likes|followers|following))?$/, (m) => profileView(main, m[1], m[2] || "posts")],
  [/^\/post\/([0-9a-f-]{36})$/, (m) => postView(main, m[1])],
  [/^\/notifications$/, () => notificationsView(main), true],
  [/^\/messages$/, () => inboxView(main), true],
  [/^\/messages\/([0-9a-f-]{36})$/, (m) => conversationView(main, m[1]), true],
  [/^\/settings$/, () => settingsView(main), true],
];

function route() {
  main.dispatchEvent(new Event("teardown"));
  const path = location.pathname;
  for (const [re, fn, needsAuth] of routes) {
    const m = re.exec(path);
    if (!m) continue;
    if (needsAuth && !state.user) return navigate("/login", { replace: true });
    Promise.resolve(fn(m)).catch((e) => { console.error(e); toast(e.message || "Something went wrong", "error"); });
    main.focus({ preventScroll: true });
    window.scrollTo(0, 0);
    return;
  }
  mount(main, h("div", { class: "card" }, h("h1", null, "Not found"), navLink("/", "Go home")));
}

setRouter(route);
window.addEventListener("popstate", route);

(async function init() {
  mount(app, h("div", { class: "layout" }, nav, main));
  try {
    const s = await get("/api/v1/auth/session");
    if (s.authenticated) { state.user = s.user; setCsrf(s.csrf_token); }
  } catch { /* offline */ }
  bootstrap();
  route();
})();
