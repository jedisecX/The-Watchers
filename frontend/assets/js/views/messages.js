import { api, del, get, qs } from "../api.js";
import { avatar, button, h, mount, timeAgo, toast } from "../dom.js";
import { pagedList, reportDialog, uploadMedia } from "../components.js";
import { onEvent, sendTyping, subscribe, unsubscribe } from "../realtime.js";
import { navLink, state } from "../state.js";

export function inboxView(root) {
  const box = h("div");
  mount(root, h("h1", { class: "page-title" }, "Messages"),
    h("p", { class: "muted small" }, "Start a conversation from someone's profile."), box);
  pagedList(box, (c) => get(`/api/v1/conversations${qs({ cursor: c })}`), (c) =>
    navLink(`/messages/${c.id}`, h("div", { class: `user-row ${c.unread ? "unread" : ""}` }, avatar(c.with),
      h("div", { class: "who" }, h("strong", null, c.with.display_name || c.with.handle),
        h("div", { class: "muted small" }, `@${c.with.handle} · ${timeAgo(c.last_message_at)}`, c.blocked ? " · blocked" : "")))),
    "No conversations yet.");
}

function messageEl(m, cid) {
  const mine = m.sender_id === state.user.id;
  const el = h("div", { class: `msg ${mine ? "mine" : ""}`, dataset: { id: m.id } });
  fill(el, m, cid, mine);
  return el;
}

function fill(el, m, cid, mine) {
  mount(el);
  if (m.deleted) { el.append(h("em", { class: "muted" }, "Message deleted")); return; }
  if (m.text) el.append(h("div", { class: "text" }, m.text));
  const v = m.media?.variants?.large || m.media?.variants?.poster_large;
  if (m.media?.variants?.video) el.append(h("video", { controls: true, preload: "none", class: "media", src: m.media.variants.video.url }));
  else if (v) el.append(h("img", { class: "media", src: v.url, alt: m.media.alt_text || "" }));
  el.append(h("div", { class: "meta muted small" }, timeAgo(m.created_at), " ",
    mine ? button("Unsend", async () => {
      try { await del(`/api/v1/conversations/${cid}/messages/${m.id}`); fill(el, { ...m, deleted: true }, cid, mine); }
      catch (e) { toast(e.message, "error"); }
    }, "link small") : button("Report", () => reportDialog({ target_type: "message", target_id: m.id }), "link small")));
}

export async function conversationView(root, cid) {
  const log = h("div", { class: "msglog", "aria-live": "polite" });
  const typing = h("div", { class: "muted small typing" });
  const input = h("textarea", { rows: 2, maxlength: 2000, placeholder: "Message", "aria-label": "Message" });
  const fileIn = h("input", { type: "file", accept: "image/*,video/mp4,video/webm", hidden: true });
  let mediaId = null;
  const attach = h("span", { class: "muted small" });
  const form = h("form", { class: "composer" }, input, attach,
    h("div", { class: "row" }, button("🖼", () => fileIn.click(), "icon"), fileIn, h("span", { class: "spacer" }),
      h("button", { type: "submit", class: "primary" }, "Send")));
  let lastTyping = 0;
  input.addEventListener("input", () => { if (Date.now() - lastTyping > 2500) { lastTyping = Date.now(); sendTyping(cid); } });
  fileIn.addEventListener("change", async () => {
    const f = fileIn.files[0]; fileIn.value = "";
    if (!f) return;
    attach.textContent = "Uploading…";
    try { mediaId = (await uploadMedia(f, "dm", (s) => { attach.textContent = s; })).id; attach.textContent = "📎 attachment ready"; }
    catch (e) { attach.textContent = ""; toast(e.message, "error"); }
  });
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (!input.value.trim() && !mediaId) return;
    try {
      const body = { text: input.value };
      if (mediaId) body.media_id = mediaId;
      const m = await api("POST", `/api/v1/conversations/${cid}/messages`, body);
      input.value = ""; mediaId = null; attach.textContent = "";
      if (!log.querySelector(`[data-id="${CSS.escape(m.id)}"]`)) log.append(messageEl(m, cid));
      log.scrollTop = log.scrollHeight;
    } catch (err) { toast(err.message, "error"); }
  });

  try {
    const page = await get(`/api/v1/conversations/${encodeURIComponent(cid)}/messages?limit=50`);
    page.items.reverse().forEach((m) => log.append(messageEl(m, cid)));
  } catch (e) { return mount(root, h("div", { class: "card" }, h("p", null, "Conversation not found."))); }
  mount(root, h("h1", { class: "page-title" }, navLink("/messages", "← Messages")), log, typing, form);
  log.scrollTop = log.scrollHeight;
  api("POST", `/api/v1/conversations/${cid}/read`, {}).catch(() => {});

  const topic = `conversation:${cid}`;
  subscribe(topic);
  let typingTimer;
  const off = onEvent((ev) => {
    if (ev.conversation_id !== cid) return;
    if (ev.type === "message" && !log.querySelector(`[data-id="${CSS.escape(ev.message.id)}"]`)) {
      log.append(messageEl(ev.message, cid)); log.scrollTop = log.scrollHeight;
      api("POST", `/api/v1/conversations/${cid}/read`, {}).catch(() => {});
    } else if (ev.type === "message_deleted") {
      const el = log.querySelector(`[data-id="${CSS.escape(ev.message_id)}"]`);
      if (el) fill(el, { id: ev.message_id, deleted: true }, cid, false);
    } else if (ev.type === "typing") {
      typing.textContent = "typing…"; clearTimeout(typingTimer);
      typingTimer = setTimeout(() => { typing.textContent = ""; }, 3000);
    }
  });
  root.addEventListener("teardown", () => { off(); unsubscribe(topic); }, { once: true });
}
