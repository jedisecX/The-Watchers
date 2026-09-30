import { get, qs } from "../api.js";
import { h, mount, toast } from "../dom.js";
import { composer, pagedList, postCard, userRow } from "../components.js";
import { state } from "../state.js";

export function homeView(root) {
  const listBox = h("div");
  const verifyNote = state.user && !state.user.email_verified
    ? h("div", { class: "notice" }, "Verify your email to post, follow and send messages. ",
        h("button", { type: "button", class: "link", onclick: async () => {
          try { await fetchResend(); toast("Verification email sent"); } catch (e) { toast(e.message, "error"); }
        } }, "Resend link"))
    : null;
  mount(root, h("h1", { class: "page-title" }, "Home"), verifyNote,
    state.user?.email_verified ? composer({ onPosted: (p) => list.prepend(postCard(p)) }) : null, listBox);
  const list = pagedList(listBox, (c) => get(`/api/v1/timeline/home${qs({ cursor: c })}`), (p) => postCard(p),
    "Your timeline is empty. Follow people from Explore to see their posts here.");
}

async function fetchResend() {
  const { post } = await import("../api.js");
  return post("/api/v1/auth/resend-verification");
}

export function exploreView(root) {
  const listBox = h("div");
  mount(root, h("h1", { class: "page-title" }, "Explore"), listBox);
  pagedList(listBox, (c) => get(`/api/v1/timeline/public${qs({ cursor: c })}`), (p) => postCard(p));
}

export function tagView(root, tag) {
  const listBox = h("div");
  mount(root, h("h1", { class: "page-title" }, `#${tag}`), listBox);
  pagedList(listBox, (c) => get(`/api/v1/hashtags/${encodeURIComponent(tag)}${qs({ cursor: c })}`), (p) => postCard(p));
}

export function searchView(root) {
  const params = new URLSearchParams(location.search);
  const input = h("input", { type: "search", value: params.get("q") || "", maxlength: 100, placeholder: "Search posts and people",
                             "aria-label": "Search" });
  const kind = params.get("type") === "users" ? "users" : "posts";
  const results = h("div");
  const form = h("form", { class: "search-bar" }, input,
    h("select", { "aria-label": "Search type", onchange: (e) => go(e.target.value) },
      h("option", { value: "posts", selected: kind === "posts" }, "Posts"),
      h("option", { value: "users", selected: kind === "users" }, "People")),
    h("button", { type: "submit", class: "primary" }, "Search"));
  function go(type) {
    history.replaceState(null, "", `/search${qs({ q: input.value, type })}`);
    run(type);
  }
  form.addEventListener("submit", (e) => { e.preventDefault(); go(form.querySelector("select").value); });
  function run(type) {
    const q = input.value.trim();
    if (q.length < 2) return mount(results, h("p", { class: "muted" }, "Type at least 2 characters."));
    if (type === "users") {
      get(`/api/v1/search/users${qs({ q })}`).then((r) => mount(results,
        r.items.length ? r.items.map((u) => userRow(u)) : h("p", { class: "empty" }, "No people found.")))
        .catch((e) => toast(e.message, "error"));
    } else {
      pagedList(results, (c) => get(`/api/v1/search/posts${qs({ q, cursor: c })}`), (p) => postCard(p), "No posts found.");
    }
  }
  mount(root, h("h1", { class: "page-title" }, "Search"), form, results);
  if (input.value) run(kind);
}
