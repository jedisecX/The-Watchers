// Moderation console. Same Trusted-Types-safe DOM rules as the main app. All authorization is
// server-side; this UI only renders what /admin/api/v1 returns (a regular user gets 403 everywhere).
import { api, get, onReauthRequired, setCsrf } from "./api.js";
import { button, field, h, modal, mount, timeAgo, toast } from "./dom.js";
import { reauthDialog } from "./components.js";
import { state } from "./state.js";

const A = "/admin/api/v1";
const app = document.getElementById("app");
const main = h("main");
onReauthRequired((code) => reauthDialog(code));

function reasonPrompt(title, { confirmHandle = null, withDays = false } = {}) {
  return new Promise((resolve) => {
    const reason = h("textarea", { rows: 3, maxlength: 500, required: true });
    const conf = confirmHandle ? h("input", { placeholder: confirmHandle, maxlength: 30 }) : null;
    const days = withDays ? h("input", { type: "number", min: 1, max: 3650, placeholder: "blank = indefinite" }) : null;
    let result = null;
    const dlg = modal(title, h("div", null, field("Reason (recorded in the audit log)", reason),
      days ? field("Days", days) : null, conf ? field(`Type @${confirmHandle} to confirm`, conf) : null),
    [button("Cancel", () => dlg.close()), button("Confirm", () => {
      if (reason.value.trim().length < 3) return toast("A reason is required", "error");
      result = { reason: reason.value.trim() };
      if (conf) result.confirm = conf.value.trim();
      if (days && days.value) result.days = Number(days.value);
      dlg.close();
    }, "primary danger")]);
    dlg.addEventListener("close", () => resolve(result));
  });
}

async function doAction(path, opts) {
  const body = await reasonPrompt(opts.title, opts);
  if (!body) return false;
  try { await api("POST", path, { ...(opts.extra || {}), ...body }); toast("Done"); return true; }
  catch (e) { toast(e.message, "error"); return false; }
}

function userCard(u) {
  return h("div", { class: "card" },
    h("strong", null, `@${u.handle}`), ` ${u.display_name || ""} `,
    h("span", { class: "badge" }, u.role), h("span", { class: `badge ${u.status}` }, u.status),
    h("div", { class: "muted small" }, `strikes ${u.strikes} · tier ${u.trust_tier} · 2FA ${u.mfa_enabled ? "on" : "off"} · joined ${timeAgo(u.created_at)}`),
    h("div", { class: "row" },
      button("Warn", () => doAction(`${A}/users/${u.id}/warn`, { title: `Warn @${u.handle}` })),
      u.status === "suspended"
        ? button("Unsuspend", () => doAction(`${A}/users/${u.id}/unsuspend`, { title: `Unsuspend @${u.handle}` }))
        : button("Suspend", () => doAction(`${A}/users/${u.id}/suspend`, { title: `Suspend @${u.handle}`, confirmHandle: u.handle, withDays: true }), "danger"),
      state.perms.has("admin:sessions") ? button("Revoke sessions", () => doAction(`${A}/users/${u.id}/revoke-sessions`, { title: "Revoke all sessions" })) : null,
      state.perms.has("admin:roles") ? h("select", { onchange: async (e) => {
        const role = e.target.value; e.target.value = "";
        if (role) await doAction(`${A}/users/${u.id}/role`, { title: `Make @${u.handle} ${role}`, confirmHandle: u.handle, extra: { role } });
      } }, h("option", { value: "" }, "Change role…"), ["user", "moderator", "admin"].map((r) => h("option", { value: r }, r))) : null));
}

async function reportsTab(status = "open") {
  const r = await get(`${A}/reports?status=${status}`);
  return h("div", null,
    h("div", { class: "row" }, ["open", "resolved", "dismissed"].map((s) => button(s, () => show("reports", s), s === status ? "primary" : ""))),
    r.items.length ? r.items.map((it) => h("article", { class: "card" },
      h("div", null, h("strong", null, it.reason), ` · ${it.target_type} · ${timeAgo(it.created_at)} · `,
        h("span", { class: "muted" }, `${it.open_reports_on_target} open report(s) on target`)),
      h("div", { class: "muted small" }, `Reporter @${it.reporter.handle} (tier ${it.reporter.trust_tier})`),
      it.details ? h("p", null, "Reporter note: ", it.details) : null,
      h("blockquote", { class: "evidence" }, it.evidence.text ?? it.evidence.bio ?? JSON.stringify(it.evidence)),
      it.target_user ? userCard(it.target_user) : null,
      it.status === "open" ? h("div", { class: "row" },
        it.target_type === "post" ? button("Remove post", async () => {
          if (await doAction(`${A}/posts/${it.target_id}/remove`, { title: "Remove post" })) show("reports");
        }, "danger") : null,
        button("Resolve", async () => { if (await doAction(`${A}/reports/${it.id}/resolve`, { title: "Resolve report", extra: { outcome: "resolved" } })) show("reports"); }),
        button("Dismiss", async () => { if (await doAction(`${A}/reports/${it.id}/resolve`, { title: "Dismiss report", extra: { outcome: "dismissed" } })) show("reports"); }))
        : h("p", { class: "muted small" }, `Status: ${it.status}`)))
      : h("p", { class: "empty" }, "No reports."));
}

async function usersTab() {
  const q = h("input", { placeholder: "handle prefix", maxlength: 30 });
  const res = h("div");
  const f = h("form", { class: "row" }, q, h("button", { type: "submit", class: "primary" }, "Find"));
  f.addEventListener("submit", async (e) => {
    e.preventDefault();
    try { const r = await get(`${A}/users?q=${encodeURIComponent(q.value)}`); mount(res, r.items.map(userCard)); }
    catch (err) { toast(err.message, "error"); }
  });
  return h("div", null, f, res);
}

async function domainsTab() {
  const r = await get(`${A}/blocked-domains`);
  const d = h("input", { placeholder: "example.com", maxlength: 253 });
  return h("div", null,
    h("div", { class: "row" }, d, button("Block domain", async () => {
      const body = await reasonPrompt(`Block ${d.value}`);
      if (!body) return;
      try { await api("POST", `${A}/blocked-domains`, { domain: d.value, ...body }); show("domains"); } catch (e) { toast(e.message, "error"); }
    }, "primary")),
    r.items.map((x) => h("div", { class: "row" }, h("code", null, x.domain), ` ${x.reason} `, h("span", { class: "spacer" }),
      button("Unblock", async () => { try { await api("DELETE", `${A}/blocked-domains/${encodeURIComponent(x.domain)}`); show("domains"); } catch (e) { toast(e.message, "error"); } }, "link"))));
}

async function auditTab() {
  const [v, r] = await Promise.all([get(`${A}/audit/verify`), get(`${A}/audit?limit=100`)]);
  return h("div", null,
    h("p", { class: v.ok ? "ok" : "error" }, v.ok ? `✅ Hash chain intact (${v.rows_checked} entries)` : `⚠️ Chain broken at seq ${v.first_bad_seq}`),
    h("table", { class: "audit" }, h("thead", null, h("tr", null, ["#", "time", "actor", "action", "target", "details"].map((c) => h("th", null, c)))),
      h("tbody", null, r.items.map((e) => h("tr", null, h("td", null, String(e.seq)), h("td", null, new Date(e.at).toLocaleString()),
        h("td", null, `${e.actor_role} ${(e.actor_id || "").slice(0, 8)}`), h("td", null, e.action),
        h("td", null, `${e.target_type} ${String(e.target_id).slice(0, 12)}`), h("td", { class: "small" }, JSON.stringify(e.details)))))));
}

const tabs = { reports: reportsTab, users: usersTab, domains: domainsTab, audit: auditTab };
async function show(name, arg) {
  mount(main, h("p", { class: "muted" }, "Loading…"));
  try { mount(main, await tabs[name](arg)); } catch (e) {
    mount(main, h("div", { class: "card" }, h("p", { class: "error" }, e.message),
      e.code === "mfa_required" ? h("p", null, "Enable two-factor authentication in Settings first.") : null));
  }
}

(async function init() {
  let s;
  try { s = await get("/api/v1/auth/session"); } catch { s = { authenticated: false }; }
  if (!s.authenticated) { location.assign("/login"); return; }
  setCsrf(s.csrf_token);
  state.user = s.user;
  let me;
  try { me = await get(`${A}/me`); } catch (e) {
    mount(app, h("div", { class: "card narrow" }, h("h1", null, "Moderation"), h("p", { class: "error" }, e.message), h("a", { href: "/" }, "Back")));
    return;
  }
  state.perms = new Set(me.permissions);
  const navItems = [["reports", "Reports"], ["users", "Users"]];
  if (state.perms.has("admin:domains")) navItems.push(["domains", "Blocked domains"]);
  if (state.perms.has("admin:audit")) navItems.push(["audit", "Audit log"]);
  mount(app, h("div", { class: "layout" },
    h("nav", { class: "side" }, h("span", { class: "brand" }, "🛡 Moderation"), h("span", { class: "muted small" }, `@${me.user.handle} · ${me.role}`),
      navItems.map(([k, l]) => button(l, () => show(k), "navbtn")), h("a", { href: "/" }, "← Back to Bastion")), main));
  show("reports");
})();
