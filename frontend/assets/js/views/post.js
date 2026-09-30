import { get, qs } from "../api.js";
import { h, mount } from "../dom.js";
import { composer, pagedList, postCard } from "../components.js";
import { navigate, state } from "../state.js";

export async function postView(root, id) {
  let p;
  try { p = await get(`/api/v1/posts/${encodeURIComponent(id)}`); }
  catch { return mount(root, h("div", { class: "card" }, h("h1", null, "Post unavailable"), h("p", { class: "muted" }, "It may have been deleted, or you don't have access."))); }
  const repliesBox = h("div");
  mount(root, h("h1", { class: "page-title" }, "Post"), postCard(p, { onRemoved: () => navigate("/") }),
    state.user?.email_verified ? composer({ replyTo: p.id, onPosted: (r) => list.prepend(postCard(r)) }) : null,
    h("h2", { class: "sub" }, "Replies"), repliesBox);
  const list = pagedList(repliesBox, (c) => get(`/api/v1/posts/${p.id}/replies${qs({ cursor: c })}`), (r) => postCard(r, { compact: true }), "No replies yet.");
}
