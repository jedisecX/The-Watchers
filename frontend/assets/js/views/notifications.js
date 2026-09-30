import { api, get, post, qs } from "../api.js";
import { avatar, button, h, mount, timeAgo, toast } from "../dom.js";
import { pagedList, userRow } from "../components.js";
import { navLink, state } from "../state.js";

const TEXT = {
  follow: "followed you", follow_request: "requested to follow you", follow_accept: "accepted your follow request",
  like: "liked your post", reply: "replied to your post", mention: "mentioned you", repost: "reposted your post",
};

export function notificationsView(root) {
  const reqBox = h("div");
  const listBox = h("div");
  mount(root, h("h1", { class: "page-title" }, "Notifications"), reqBox, listBox);
  get("/api/v1/follow-requests").then((r) => {
    if (!r.items.length) return;
    mount(reqBox, h("section", { class: "card" }, h("h2", null, "Follow requests"),
      r.items.map((it) => {
        const row = userRow(it.user, h("div", { class: "row" },
          button("Accept", () => decide("accept"), "primary"), button("Reject", () => decide("reject"))));
        async function decide(d) {
          try { await api("POST", `/api/v1/follow-requests/${encodeURIComponent(it.user.handle)}/${d}`); row.remove(); }
          catch (e) { toast(e.message, "error"); }
        }
        return row;
      })));
  }).catch(() => {});
  pagedList(listBox, (c) => get(`/api/v1/notifications${qs({ cursor: c })}`), (n) =>
    h("div", { class: `notif ${n.read ? "" : "unread"}` }, avatar(n.actor, 32),
      h("div", null,
        navLink(`/@${n.actor.handle}`, h("strong", null, n.actor.display_name || n.actor.handle)),
        ` ${TEXT[n.type] || n.type} `, h("span", { class: "muted small" }, timeAgo(n.created_at)),
        n.post ? h("div", null, navLink(`/post/${n.post.id}`, h("span", { class: "muted" }, n.post.text))) : null)),
    "No notifications yet.");
  post("/api/v1/notifications/read").then(() => { state.unread = 0; document.dispatchEvent(new Event("unread")); }).catch(() => {});
}
