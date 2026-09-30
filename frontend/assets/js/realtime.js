// Realtime client: one socket per tab, exponential backoff reconnect, topic re-subscription.
// Events are *rendered* by views using the same textContent-only helpers as HTTP data.
const listeners = new Set();
const topics = new Set();
let ws = null;
let backoff = 1000;
let stopped = true;
let pingTimer = null;

function url() {
  const proto = location.protocol === "https:" ? "wss:" : "ws:";
  return `${proto}//${location.host}/api/v1/ws`;
}

export function onEvent(fn) { listeners.add(fn); return () => listeners.delete(fn); }

export function subscribe(topic) {
  topics.add(topic);
  if (ws?.readyState === 1) ws.send(JSON.stringify({ type: "subscribe", topic }));
}

export function unsubscribe(topic) {
  topics.delete(topic);
  if (ws?.readyState === 1) ws.send(JSON.stringify({ type: "unsubscribe", topic }));
}

export function sendTyping(conversationId) {
  if (ws?.readyState === 1) ws.send(JSON.stringify({ type: "typing", conversation_id: conversationId }));
}

export function start() {
  stopped = false;
  connect();
}

export function stop() {
  stopped = true;
  clearInterval(pingTimer);
  ws?.close();
  ws = null;
}

function connect() {
  if (stopped) return;
  ws = new WebSocket(url());
  ws.addEventListener("open", () => {
    backoff = 1000;
    for (const t of topics) ws.send(JSON.stringify({ type: "subscribe", topic: t }));
    clearInterval(pingTimer);
    pingTimer = setInterval(() => ws?.readyState === 1 && ws.send('{"type":"ping"}'), 30000);
  });
  ws.addEventListener("message", (m) => {
    let ev;
    try { ev = JSON.parse(m.data); } catch { return; }
    if (!ev || typeof ev.type !== "string") return;
    for (const fn of listeners) { try { fn(ev); } catch (e) { console.error(e); } }
  });
  ws.addEventListener("close", (e) => {
    clearInterval(pingTimer);
    if (stopped || e.code === 4001 || e.code === 4003) {  // revoked / disabled: don't hammer the server
      if (e.code === 4001 || e.code === 4003) listeners.forEach((fn) => fn({ type: "session_ended" }));
      return;
    }
    setTimeout(connect, backoff + Math.random() * 500);
    backoff = Math.min(backoff * 2, 30000);
  });
}
