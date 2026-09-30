import { api, get, qs } from "../api.js";
import { avatar, button, h, mount, toast } from "../dom.js";
import { pagedList, postCard, reportDialog, userRow } from "../components.js";
import { navigate, navLink, state } from "../state.js";

export async function profileView(root, handle, tab = "posts") {
  let u;
  try { u = await get(`/api/v1/users/${encodeURIComponent(handle)}`); }
  catch (e) { return mount(root, h("div", { class: "card" }, h("h1", null, "This account doesn't exist"), h("p", { class: "muted" }, "Try searching for another."))); }
  const rel = u.relationship || {};
  const me = state.user && state.user.id === u.id;
  const actions = h("div", { class: "row" });

  function renderActions() {
    mount(actions);
    if (!state.user) return actions.append(navLink("/login", "Sign in to follow"));
    if (me) return actions.append(navLink("/settings", "Edit profile"));
    if (rel.blocking) {
      return actions.append(h("span", { class: "muted" }, "You blocked this account. "),
        button("Unblock", () => act("DELETE", "block", () => { rel.blocking = false; })));
    }
    const fl = rel.following ? "Unfollow" : rel.requested ? "Requested" : "Follow";
    actions.append(
      button(fl, () => act(rel.following || rel.requested ? "DELETE" : "POST", "follow", (r) => {
        rel.following = r.state === "following"; rel.requested = r.state === "requested";
      }), rel.following ? "" : "primary"),
      button("Message", async () => {
        try { const c = await api("POST", "/api/v1/conversations", { handle: u.handle }); navigate(`/messages/${c.id}`); }
        catch (e) { toast(e.message, "error"); }
      }),
      button(rel.muting ? "Unmute" : "Mute", () => act(rel.muting ? "DELETE" : "POST", "mute", () => { rel.muting = !rel.muting; })),
      button("Block", () => {
        if (confirm(`Block @${u.handle}? They won't be able to see your posts, follow you, or message you.`)) {
          act("POST", "block", () => { rel.blocking = true; rel.following = false; });
        }
      }, "danger"),
      button("Report", () => reportDialog({ target_type: "user", handle: u.handle }), "link"));
  }
  async function act(method, what, after) {
    try { const r = await api(method, `/api/v1/users/${encodeURIComponent(u.handle)}/${what}`); after(r || {}); renderActions(); }
    catch (e) { toast(e.message, "error"); }
  }
  renderActions();

  const tabs = ["posts", "replies", "likes", "followers", "following"].map((t) =>
    navLink(t === "posts" ? `/@${u.handle}` : `/@${u.handle}/${t}`, h("span", { class: tab === t ? "tab on" : "tab" }, t)));
  const body = h("div");
  mount(root,
    h("section", { class: "profile card" },
      h("div", { class: "row" }, avatar(u, 72), h("div", null,
        h("h1", null, u.display_name || u.handle, u.badge ? h("span", { class: "badge" }, " staff") : null),
        h("div", { class: "muted" }, `@${u.handle}`, u.is_private ? " 🔒" : "", rel.followed_by ? " · Follows you" : ""))),
      u.bio ? h("p", { class: "bio" }, u.bio) : null,
      h("div", { class: "muted small" }, `${u.posts_count} posts · ${u.followers_count} followers · ${u.following_count} following · joined ${new Date(u.created_at).toLocaleDateString()}`),
      actions),
    h("nav", { class: "tabs" }, tabs), body);

  const base = `/api/v1/users/${encodeURIComponent(u.handle)}`;
  if (tab === "followers" || tab === "following") {
    pagedList(body, (c) => get(`${base}/${tab}${qs({ cursor: c })}`), (x) => userRow(x), "No one yet.");
  } else if (tab === "likes") {
    pagedList(body, (c) => get(`${base}/likes${qs({ cursor: c })}`), (p) => postCard(p), "No likes to show.");
  } else {
    pagedList(body, (c) => get(`${base}/posts${qs({ cursor: c, replies: tab === "replies" ? "true" : undefined })}`),
      (p) => postCard(p), u.is_private && !rel.following && !me ? "This account's posts are protected." : "No posts yet.");
  }
}
