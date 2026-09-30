import { api, del, get, patch, post, setCsrf } from "../api.js";
import { avatar, button, downloadJSON, field, h, modal, mount, timeAgo, toast } from "../dom.js";
import { qrCanvas, uploadMedia, userRow } from "../components.js";
import { createCredential, passkeysSupported } from "../webauthn.js";
import { navigate, state } from "../state.js";

const section = (title, ...children) => h("section", { class: "card" }, h("h2", null, title), ...children);

async function refreshMe() {
  const s = await get("/api/v1/auth/session");
  if (s.authenticated) { state.user = s.user; setCsrf(s.csrf_token); }
}

export async function settingsView(root) {
  await refreshMe();
  const u = state.user;
  mount(root, h("h1", { class: "page-title" }, "Settings"),
    profileSection(u), privacySection(u), securitySection(u), await passkeySection(), await sessionsSection(),
    await tokensSection(), await listsSection(), dataSection(u));
}

function profileSection(u) {
  const name = h("input", { value: u.display_name, maxlength: 50 });
  const bio = h("textarea", { maxlength: 300, rows: 3 });
  bio.value = u.bio;
  const avBox = h("span", null, avatar(u, 64));
  const fileIn = h("input", { type: "file", accept: "image/jpeg,image/png,image/webp,image/gif", hidden: true });
  fileIn.addEventListener("change", async () => {
    const f = fileIn.files[0]; fileIn.value = "";
    if (!f) return;
    try {
      const m = await uploadMedia(f, "avatar");
      const me = await patch("/api/v1/me", { version: state.user.version, avatar_media_id: m.id });
      state.user = { ...state.user, ...me }; mount(avBox, avatar(state.user, 64)); toast("Avatar updated");
    } catch (e) { toast(e.message, "error"); }
  });
  return section("Profile",
    h("div", { class: "row" }, avBox, button("Change avatar", () => fileIn.click()), fileIn),
    field("Display name", name), field("Bio", bio),
    button("Save profile", async () => {
      try {
        const me = await patch("/api/v1/me", { version: state.user.version, display_name: name.value, bio: bio.value });
        state.user = { ...state.user, ...me }; toast("Saved");
      } catch (e) { toast(e.code === "version_conflict" ? "Changed elsewhere — reload and retry." : e.message, "error"); }
    }, "primary"));
}

function privacySection(u) {
  const s = u.settings;
  const priv = h("input", { type: "checkbox", checked: s.is_private });
  const disc = h("input", { type: "checkbox", checked: s.discoverable });
  const likes = h("input", { type: "checkbox", checked: s.show_likes });
  const dm = h("select", null, ["everyone", "following", "nobody"].map((v) =>
    h("option", { value: v, selected: s.dm_policy === v }, { everyone: "Everyone", following: "People I follow", nobody: "No one" }[v])));
  const vis = h("select", null, h("option", { value: "public", selected: s.default_visibility === "public" }, "Public"),
    h("option", { value: "followers", selected: s.default_visibility === "followers" }, "Followers only"));
  return section("Privacy",
    h("label", { class: "check" }, priv, " Private account (approve followers; posts visible to followers only)"),
    h("label", { class: "check" }, disc, " Let people find me in search"),
    h("label", { class: "check" }, likes, " Show my likes on my profile"),
    field("Who can message me", dm), field("Default post visibility", vis),
    button("Save privacy settings", async () => {
      try {
        const me = await patch("/api/v1/me", { version: state.user.version, is_private: priv.checked, discoverable: disc.checked,
          show_likes: likes.checked, dm_policy: dm.value, default_visibility: vis.value });
        state.user = { ...state.user, ...me }; toast("Privacy settings saved");
      } catch (e) { toast(e.message, "error"); }
    }, "primary"));
}

function showCodes(codes) {
  const pre = h("pre", { class: "codes" }, codes.join("\n"));
  const dlg = modal("Recovery codes", h("div", null,
    h("p", null, "Store these somewhere safe. Each code works once. They are shown only now."), pre),
    [button("Download", () => downloadJSON("bastion-recovery-codes.json", { codes })), button("Done", () => dlg.close(), "primary")]);
}

function securitySection(u) {
  const cur = h("input", { type: "password", autocomplete: "current-password" });
  const nw = h("input", { type: "password", autocomplete: "new-password", minlength: 12 });
  const email = h("input", { type: "email", autocomplete: "email", placeholder: u.email });
  const mfaBox = h("div");
  function renderMfa() {
    if (state.user.mfa_enabled) {
      const code = h("input", { inputmode: "numeric", placeholder: "Current code", maxlength: 40 });
      mount(mfaBox, h("p", null, "✅ Two-factor authentication is on."),
        state.user.recovery_codes_left !== undefined ? h("p", { class: "muted small" }, `${state.user.recovery_codes_left} recovery codes left`) : null,
        button("New recovery codes", async () => {
          try { showCodes((await post("/api/v1/account/mfa/recovery-codes")).recovery_codes); } catch (e) { toast(e.message, "error"); }
        }),
        state.user.role === "user" ? h("div", { class: "row" }, code, button("Turn off 2FA", async () => {
          try { await post("/api/v1/account/mfa/totp/disable", { code: code.value.trim() }); await refreshMe(); renderMfa(); toast("2FA disabled"); }
          catch (e) { toast(e.message, "error"); }
        }, "danger")) : h("p", { class: "muted small" }, "Staff accounts must keep 2FA on."));
    } else {
      mount(mfaBox, h("p", null, "Protect your account with an authenticator app."), button("Set up 2FA", async () => {
        try {
          const s = await post("/api/v1/account/mfa/totp/setup");
          const code = h("input", { inputmode: "numeric", autocomplete: "one-time-code", maxlength: 6 });
          const dlg = modal("Set up two-factor authentication", h("div", null,
            h("p", null, "Scan with your authenticator app, or enter the key manually."), qrCanvas(s.otpauth_uri),
            h("p", null, h("code", { class: "secret" }, s.secret)), field("Code from the app", code)),
            [button("Cancel", () => dlg.close()), button("Enable", async () => {
              try {
                const r = await post("/api/v1/account/mfa/totp/enable", { code: code.value.trim() });
                dlg.close(); await refreshMe(); renderMfa(); showCodes(r.recovery_codes);
              } catch (e) { toast(e.message, "error"); }
            }, "primary")]);
        } catch (e) { toast(e.message, "error"); }
      }, "primary"));
    }
  }
  renderMfa();
  return section("Security",
    h("h3", null, "Password"), field("Current password", cur), field("New password", nw),
    button("Change password", async () => {
      try {
        const r = await post("/api/v1/account/password", { current_password: cur.value, new_password: nw.value });
        setCsrf(r.csrf_token); cur.value = ""; nw.value = ""; toast("Password changed. Other sessions were signed out.");
      } catch (e) { toast(e.message, "error"); }
    }),
    h("h3", null, "Email"), h("p", { class: "muted small" }, `Current: ${u.email}${u.email_verified ? " (verified)" : " (unverified)"}`),
    field("New email", email), button("Change email", async () => {
      try { await post("/api/v1/account/email", { new_email: email.value }); toast("Check the new address for a confirmation link."); }
      catch (e) { toast(e.message, "error"); }
    }),
    h("h3", null, "Two-factor authentication"), mfaBox);
}

async function passkeySection() {
  const box = h("div");
  async function render() {
    const r = await get("/api/v1/account/passkeys");
    mount(box, r.items.length ? r.items.map((k) => h("div", { class: "row" }, "🔑 ", h("strong", null, k.name),
      h("span", { class: "muted small" }, ` added ${timeAgo(k.created_at)}${k.last_used_at ? `, used ${timeAgo(k.last_used_at)}` : ""}`),
      h("span", { class: "spacer" }), button("Remove", async () => {
        if (!confirm("Remove this passkey?")) return;
        try { await del(`/api/v1/account/passkeys/${k.id}`); render(); } catch (e) { toast(e.message, "error"); }
      }, "link danger"))) : h("p", { class: "muted" }, "No passkeys yet."));
  }
  await render();
  return section("Passkeys", h("p", { class: "muted small" }, "Passkeys are phishing-resistant and replace your password + code."), box,
    passkeysSupported() ? button("Add a passkey", async () => {
      try {
        const b = await post("/api/v1/account/passkeys/register/begin");
        const cred = await createCredential(b.options);
        const name = prompt("Name this passkey", "My device") || "Passkey";
        await post("/api/v1/account/passkeys/register/finish", { challenge_id: b.challenge_id, credential: cred, name });
        render(); toast("Passkey added");
      } catch (e) { toast(e.message || "Passkey registration failed", "error"); }
    }, "primary") : h("p", { class: "muted small" }, "This browser doesn't support passkeys."));
}

async function sessionsSection() {
  const box = h("div");
  async function render() {
    const r = await get("/api/v1/account/sessions");
    mount(box, r.items.map((s) => h("div", { class: "row session" },
      h("div", null, h("strong", null, s.current ? "This device" : (s.user_agent || "Unknown device").slice(0, 60)),
        h("div", { class: "muted small" }, `${s.ip_prefix} · ${s.auth_method} · active ${timeAgo(s.last_seen_at)}`)),
      h("span", { class: "spacer" }),
      s.current ? null : button("Sign out", async () => {
        try { await del(`/api/v1/account/sessions/${s.id}`); render(); } catch (e) { toast(e.message, "error"); }
      }, "link danger"))));
  }
  await render();
  return section("Active sessions", box, button("Sign out all other sessions", async () => {
    try { const r = await post("/api/v1/account/sessions/revoke-others"); toast(`Signed out ${r.revoked} sessions`); render(); }
    catch (e) { toast(e.message, "error"); }
  }, "danger"));
}

async function tokensSection() {
  const box = h("div");
  const name = h("input", { maxlength: 50, placeholder: "e.g. my bot" });
  const scopes = ["read", "write", "dm"].map((s) => h("input", { type: "checkbox", value: s, checked: s === "read" }));
  async function render() {
    const r = await get("/api/v1/account/tokens");
    mount(box, r.items.map((t) => h("div", { class: "row" }, h("code", null, `${t.prefix}…`), ` ${t.name} [${t.scopes.join(", ")}] `,
      h("span", { class: "muted small" }, `expires ${new Date(t.expires_at).toLocaleDateString()}`), h("span", { class: "spacer" }),
      button("Revoke", async () => { try { await del(`/api/v1/account/tokens/${t.id}`); render(); } catch (e) { toast(e.message, "error"); } }, "link danger"))));
  }
  await render();
  return section("API tokens", h("p", { class: "muted small" }, "Tokens can't access account security settings or the admin API."), box,
    field("Name", name), h("div", { class: "row" }, scopes.map((c) => h("label", { class: "check" }, c, ` ${c.value} `))),
    button("Create token", async () => {
      try {
        const t = await post("/api/v1/account/tokens", { name: name.value || "token", scopes: scopes.filter((c) => c.checked).map((c) => c.value) });
        modal("New API token", h("div", null, h("p", null, "Copy it now; it won't be shown again."), h("pre", { class: "codes" }, t.token)),
          [button("Done", (e) => e.target.closest("dialog").close(), "primary")]);
        render();
      } catch (e) { toast(e.message, "error"); }
    }));
}

async function listsSection() {
  const [blocks, mutes] = await Promise.all([get("/api/v1/blocks"), get("/api/v1/mutes")]);
  const mk = (items, what) => items.length ? items.map((u) => {
    const row = userRow(u, button(`Un${what}`, async () => {
      try { await api("DELETE", `/api/v1/users/${encodeURIComponent(u.handle)}/${what}`); row.remove(); } catch (e) { toast(e.message, "error"); }
    }, "link"));
    return row;
  }) : h("p", { class: "muted" }, "None.");
  return section("Blocked & muted", h("h3", null, "Blocked"), mk(blocks.items, "block"), h("h3", null, "Muted"), mk(mutes.items, "mute"));
}

function dataSection(u) {
  const confirmIn = h("input", { placeholder: u.handle, maxlength: 30 });
  return section("Your data",
    button("Download my data (JSON)", async () => {
      try { downloadJSON(`bastion-export-${u.handle}.json`, await get("/api/v1/account/export")); } catch (e) { toast(e.message, "error"); }
    }),
    h("h3", null, "Delete account"),
    h("p", { class: "muted small" }, "Your profile and posts disappear immediately; data is permanently purged after 30 days."),
    field("Type your handle to confirm", confirmIn),
    button("Delete my account", async () => {
      try { await post("/api/v1/account/delete", { confirm_handle: confirmIn.value }); state.user = null; navigate("/login"); toast("Account deleted"); }
      catch (e) { toast(e.message, "error"); }
    }, "danger"));
}
