// API client. Same-origin only (no CORS), JSON only, CSRF token on every unsafe request.
// Credentials are an HttpOnly cookie the script cannot read; the CSRF token lives in memory only.

let csrfToken = null;
let reauthHandler = null;

export class ApiError extends Error {
  constructor(status, code, message, details) {
    super(message || code);
    this.status = status;
    this.code = code;
    this.details = details;
  }
}

export function setCsrf(t) { csrfToken = t || null; }
export function onReauthRequired(fn) { reauthHandler = fn; }

const UNSAFE = new Set(["POST", "PUT", "PATCH", "DELETE"]);

export async function api(method, path, body, { raw = null, contentType = null, retry = true } = {}) {
  if (!path.startsWith("/api/") && !path.startsWith("/admin/api/")) throw new Error("bad api path");
  const headers = { Accept: "application/json" };
  if (UNSAFE.has(method) && csrfToken) headers["X-CSRF-Token"] = csrfToken;
  let payload;
  if (raw) { payload = raw; headers["Content-Type"] = contentType || "application/octet-stream"; }
  else if (body !== undefined) { payload = JSON.stringify(body); headers["Content-Type"] = "application/json"; }
  const res = await fetch(path, { method, headers, body: payload, credentials: "same-origin",
                                  redirect: "error", cache: "no-store", mode: "same-origin" });
  if (res.status === 204) return null;
  let data = null;
  try { data = await res.json(); } catch { /* non-JSON */ }
  if (!res.ok) {
    const e = data?.error || {};
    const err = new ApiError(res.status, e.code || "error", e.message || `Request failed (${res.status})`, e.details);
    if (retry && res.status === 403 && (e.code === "reauth_required" || e.code === "stepup_required") && reauthHandler) {
      if (await reauthHandler(e.code)) return api(method, path, body, { raw, contentType, retry: false });
    }
    throw err;
  }
  return data;
}

export const get = (p) => api("GET", p);
export const post = (p, b) => api("POST", p, b ?? {});
export const patch = (p, b) => api("PATCH", p, b);
export const del = (p) => api("DELETE", p);

export function qs(params) {
  const u = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== "") u.set(k, v);
  const s = u.toString();
  return s ? `?${s}` : "";
}
