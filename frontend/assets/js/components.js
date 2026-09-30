import { api, ApiError, del, post } from "./api.js";
import { avatar, button, field, h, modal, mount, renderSegments, timeAgo, toast } from "./dom.js";
import { getAssertion, passkeysSupported } from "./webauthn.js";
import { state, navigate, navLink } from "./state.js";

// ------------------------------------------------------------------------------------------ posts
export function postCard(p, { onRemoved, compact = false } = {}) {
  const liked = !!p.viewer?.liked;
  const reposted = !!p.viewer?.reposted;
  const likeBtn = h("button", { type: "button", class: `act ${liked ? "on" : ""}`, "aria-pressed": String(liked),
                               "aria-label": "Like" }, `♥ ${p.like_count}`);
  const rpBtn = h("button", { type: "button", class: `act ${reposted ? "on" : ""}`, "aria-pressed": String(reposted),
                             "aria-label": "Repost", disabled: p.visibility !== "public" }, `⟲ ${p.repost_count}`);
  let likes = p.like_count, reps = p.repost_count, isLiked = liked, isRep = reposted;
  likeBtn.addEventListener("click", async () => {
    if (!state.user) return navigate("/login");
    try {
      await api(isLiked ? "DELETE" : "POST", `/api/v1/posts/${p.id}/like`);
      isLiked = !isLiked; likes += isLiked ? 1 : -1;
      likeBtn.textContent = `♥ ${likes}`; likeBtn.classList.toggle("on", isLiked);
    } catch (e) { toast(e.message, "error"); }
  });
  rpBtn.addEventListener("click", async () => {
    if (!state.user) return navigate("/login");
    try {
      await api(isRep ? "DELETE" : "POST", `/api/v1/posts/${p.id}/repost`);
      isRep = !isRep; reps += isRep ? 1 : -1;
      rpBtn.textContent = `⟲ ${reps}`; rpBtn.classList.toggle("on", isRep);
    } catch (e) { toast(e.message, "error"); }
  });
  const menu = [];
  if (p.viewer?.is_author) {
    menu.push(button("Delete", async () => {
      if (!confirm("Delete this post permanently?")) return;
      try { await del(`/api/v1/posts/${p.id}`); card.remove(); onRemoved?.(); toast("Post deleted"); }
      catch (e) { toast(e.message, "error"); }
    }, "link danger"));
  } else if (state.user) {
    menu.push(button("Report", () => reportDialog({ target_type: "post", target_id: p.id }), "link"));
  }
  const media = (p.media || []).filter((m) => m.state === "ready").map((m) => {
    if (m.kind === "video" && m.variants.video) {
      return h("video", { controls: true, preload: "none", class: "media",
                          poster: m.variants.poster_large?.url, src: m.variants.video.url });
    }
    const v = m.variants.large || m.variants.thumb;
    return v ? h("img", { class: "media", src: v.url, alt: m.alt_text || "", loading: "lazy" }) : null;
  });
  const card = h("article", { class: `post ${compact ? "compact" : ""}` },
    p.reposted_by ? h("div", { class: "muted small" }, "⟲ reposted by ", navLink(`/@${p.reposted_by.handle}`, `@${p.reposted_by.handle}`)) : null,
    h("header", null,
      avatar(p.author),
      h("div", { class: "who" },
        navLink(`/@${p.author.handle}`, h("strong", null, p.author.display_name || p.author.handle)),
        h("span", { class: "muted" }, ` @${p.author.handle} · `),
        navLink(`/post/${p.id}`, h("time", { datetime: p.created_at, title: new Date(p.created_at).toLocaleString() },
          timeAgo(p.created_at))),
        p.visibility === "followers" ? h("span", { class: "badge", title: "Followers only" }, "🔒") : null)),
    p.reply_to_id ? h("div", { class: "muted small" }, "↳ ", navLink(`/post/${p.reply_to_id}`, "in reply to a post")) : null,
    h("p", { class: "body" }, renderSegments(p.segments, onNav)),
    media.length ? h("div", { class: `media-grid n${media.length}` }, media) : null,
    h("footer", null,
      h("button", { type: "button", class: "act", "aria-label": "Reply", onclick: () => navigate(`/post/${p.id}`) }, `💬 ${p.reply_count}`),
      rpBtn, likeBtn, h("span", { class: "spacer" }), menu));
  return card;
}

export function onNav(e) {
  const a = e.currentTarget;
  const href = a.getAttribute("href");
  if (href && href.startsWith("/") && !e.metaKey && !e.ctrlKey) { e.preventDefault(); navigate(href); }
}

// ------------------------------------------------------------------------------------------ composer
export function composer({ replyTo = null, onPosted } = {}) {
  const ta = h("textarea", { maxlength: 500, rows: replyTo ? 2 : 3, placeholder: replyTo ? "Post your reply" : "What's happening?",
                             "aria-label": "Post text" });
  const count = h("span", { class: "muted small" }, "500");
  const vis = h("select", { "aria-label": "Visibility" },
    h("option", { value: "public" }, "🌐 Public"), h("option", { value: "followers" }, "🔒 Followers"));
  vis.value = state.user?.settings?.default_visibility || "public";
  const fileIn = h("input", { type: "file", accept: "image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm", hidden: true });
  const previews = h("div", { class: "previews" });
  const media = [];
  const sendBtn = h("button", { type: "submit", class: "primary" }, replyTo ? "Reply" : "Post");
  ta.addEventListener("input", () => { count.textContent = String(500 - [...ta.value].length); });
  fileIn.addEventListener("change", async () => {
    const f = fileIn.files[0];
    fileIn.value = "";
    if (!f || media.length >= 4) return;
    const chip = h("span", { class: "chip" }, "Uploading…");
    previews.append(chip);
    try {
      const m = await uploadMedia(f, "post", (s) => { chip.textContent = s; });
      media.push(m.id);
      const thumb = m.variants.thumb || m.variants.poster_thumb;
      mount(chip, thumb ? h("img", { src: thumb.url, alt: "", class: "thumb" }) : "Video ready",
        button("✕", () => { media.splice(media.indexOf(m.id), 1); chip.remove(); }, "link"));
    } catch (e) { chip.remove(); toast(e.message, "error"); }
  });
  const form = h("form", { class: "composer" },
    ta, previews,
    h("div", { class: "row" },
      button("🖼", () => fileIn.click(), "icon"), fileIn,
      replyTo ? null : vis, h("span", { class: "spacer" }), count, sendBtn));
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (!ta.value.trim()) return;
    sendBtn.disabled = true;
    try {
      const body = { text: ta.value, media_ids: media };
      if (replyTo) body.reply_to_id = replyTo; else body.visibility = vis.value;
      const created = await post("/api/v1/posts", body);
      ta.value = ""; media.length = 0; mount(previews); count.textContent = "500";
      onPosted?.(created);
    } catch (err) { toast(err.message, "error"); }
    finally { sendBtn.disabled = false; }
  });
  return form;
}

export async function uploadMedia(file, purpose, onStatus) {
  if (file.size > 100 * 1024 * 1024) throw new Error("File too large");
  const buf = await file.arrayBuffer();
  const m = await api("POST", `/api/v1/media?purpose=${encodeURIComponent(purpose)}`, undefined,
                      { raw: buf, contentType: "application/octet-stream" });
  onStatus?.("Processing…");
  for (let i = 0; i < 120; i++) {
    await new Promise((r) => setTimeout(r, i < 10 ? 500 : 1500));
    const s = await api("GET", `/api/v1/media/${m.id}`);
    if (s.state === "ready") return s;
    if (s.state === "failed") throw new ApiError(400, s.error_code, `Upload rejected (${s.error_code})`);
  }
  throw new Error("Processing timed out");
}

// ------------------------------------------------------------------------------------------ lists
export function pagedList(container, fetchPage, render, emptyText = "Nothing here yet.") {
  let cursor = null, loading = false, done = false;
  const list = h("div", { class: "list" });
  const more = button("Load more", () => load(), "more");
  mount(container, list, more);
  async function load() {
    if (loading || done) return;
    loading = true; more.disabled = true;
    try {
      const page = await fetchPage(cursor);
      for (const item of page.items) list.append(render(item));
      cursor = page.next_cursor;
      done = !cursor;
      if (done) more.remove();
      if (!list.firstChild) list.append(h("p", { class: "empty" }, emptyText));
    } catch (e) { toast(e.message, "error"); }
    finally { loading = false; more.disabled = false; }
  }
  load();
  return { prepend: (el) => { list.querySelector(".empty")?.remove(); list.prepend(el); } };
}

export function userRow(u, extra) {
  return h("div", { class: "user-row" }, avatar(u),
    h("div", { class: "who" }, navLink(`/@${u.handle}`, h("strong", null, u.display_name || u.handle)),
      h("div", { class: "muted small" }, `@${u.handle}`)), extra || null);
}

// ------------------------------------------------------------------------------------------ dialogs
const REASONS = [["spam", "Spam"], ["harassment", "Harassment"], ["hate", "Hate"], ["violence", "Violence"],
  ["sexual", "Sexual content"], ["self_harm", "Self-harm"], ["impersonation", "Impersonation"],
  ["illegal", "Illegal content"], ["other", "Other"]];

export function reportDialog(target) {
  const sel = h("select", null, REASONS.map(([v, l]) => h("option", { value: v }, l)));
  const details = h("textarea", { maxlength: 1000, rows: 3 });
  const dlg = modal("Report", h("div", null, field("Reason", sel), field("Details (optional)", details)), [
    button("Cancel", () => dlg.close()),
    button("Submit report", async () => {
      try {
        await post("/api/v1/reports", { ...target, reason: sel.value, details: details.value });
        toast("Thanks — our moderators will review it."); dlg.close();
      } catch (e) { toast(e.message, "error"); }
    }, "primary danger")]);
}

// Re-authentication (step-up) dialog; resolves true on success.
export function reauthDialog(code) {
  return new Promise((resolve) => {
    const pw = h("input", { type: "password", autocomplete: "current-password" });
    const otp = h("input", { inputmode: "numeric", autocomplete: "one-time-code", placeholder: "123456 or recovery code" });
    const err = h("p", { class: "error", role: "alert" });
    let ok = false;
    const doPw = async () => {
      try {
        const body = { password: pw.value };
        if (otp.value.trim()) body.code = otp.value.trim();
        await api("POST", "/api/v1/auth/reauth", body, { retry: false });
        ok = true; dlg.close();
      } catch (e) { err.textContent = e.message; }
    };
    const doPk = async () => {
      try {
        const b = await api("POST", "/api/v1/auth/reauth/passkey/begin", {}, { retry: false });
        const cred = await getAssertion(b.options);
        await api("POST", "/api/v1/auth/reauth/passkey/finish", { challenge_id: b.challenge_id, credential: cred }, { retry: false });
        ok = true; dlg.close();
      } catch (e) { err.textContent = e.message || "Passkey failed"; }
    };
    const dlg = modal(code === "stepup_required" ? "Verify it's you (staff)" : "Confirm it's you",
      h("form", { onsubmit: (e) => { e.preventDefault(); doPw(); } },
        h("p", { class: "muted" }, "For your security, confirm your identity to continue."),
        field("Password", pw), state.user?.mfa_enabled || code === "stepup_required" ? field("Authentication code", otp) : null,
        err, h("button", { type: "submit", hidden: true })),
      [button("Cancel", () => dlg.close()),
       state.user?.has_passkeys && passkeysSupported() ? button("Use passkey", doPk) : null,
       button("Confirm", doPw, "primary")]);
    dlg.addEventListener("close", () => resolve(ok));
    pw.focus();
  });
}

export function qrCanvas(text, scale = 4) {
  // eslint-disable-next-line no-undef
  const qr = window.qrcode(0, "M");
  qr.addData(text);
  qr.make();
  const n = qr.getModuleCount();
  const c = h("canvas", { width: (n + 8) * scale, height: (n + 8) * scale, class: "qr", "aria-label": "QR code" });
  const ctx = c.getContext("2d");
  ctx.fillStyle = "#fff"; ctx.fillRect(0, 0, c.width, c.height);
  ctx.fillStyle = "#000";
  for (let r = 0; r < n; r++) for (let col = 0; col < n; col++) {
    if (qr.isDark(r, col)) ctx.fillRect((col + 4) * scale, (r + 4) * scale, scale, scale);
  }
  return c;
}
