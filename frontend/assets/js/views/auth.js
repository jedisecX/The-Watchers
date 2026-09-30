import { api, get, setCsrf } from "../api.js";
import { button, field, h, mount, toast } from "../dom.js";
import { solvePow } from "../sha256.js";
import { getAssertion, passkeysSupported } from "../webauthn.js";
import { navigate, navLink, state } from "../state.js";

function finishLogin(res, bootstrap) {
  setCsrf(res.csrf_token);
  state.user = res.user;
  bootstrap();
  navigate("/", { replace: true });
}

export function loginView(root, bootstrap) {
  const ident = h("input", { autocomplete: "username", required: true, maxlength: 254 });
  const pw = h("input", { type: "password", autocomplete: "current-password", required: true, maxlength: 1024 });
  const err = h("p", { class: "error", role: "alert" });
  const submit = h("button", { type: "submit", class: "primary" }, "Sign in");
  const form = h("form", { class: "card narrow" },
    h("h1", null, "Sign in"), field("Username or email", ident), field("Password", pw), err, submit,
    passkeysSupported() ? button("Sign in with a passkey", passkeyLogin, "wide") : null,
    h("p", { class: "muted small" }, navLink("/reset-password", "Forgot password?"), " · ",
      navLink("/register", "Create account")));
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    err.textContent = "";
    submit.disabled = true;
    try {
      const res = await api("POST", "/api/v1/auth/login", { identifier: ident.value, password: pw.value });
      pw.value = "";
      if (res.mfa_required) mfaStep(res); else finishLogin(res, bootstrap);
    } catch (ex) { err.textContent = ex.message; } finally { submit.disabled = false; }
  });

  function mfaStep(res) {
    const code = h("input", { inputmode: "numeric", autocomplete: "one-time-code", required: true, maxlength: 40,
                              placeholder: "6-digit code or recovery code" });
    const e2 = h("p", { class: "error", role: "alert" });
    const f2 = h("form", { class: "card narrow" }, h("h1", null, "Two-factor authentication"),
      h("p", { class: "muted" }, "Enter the code from your authenticator app, or a recovery code."),
      field("Code", code), e2, h("button", { type: "submit", class: "primary" }, "Verify"));
    f2.addEventListener("submit", async (ev) => {
      ev.preventDefault();
      try {
        finishLogin(await api("POST", "/api/v1/auth/login/mfa", { mfa_token: res.mfa_token, code: code.value.trim() }), bootstrap);
      } catch (ex) {
        e2.textContent = ex.message;
        if (ex.code === "mfa_token_invalid") setTimeout(() => loginView(root, bootstrap), 1500);
      }
    });
    mount(root, f2);
    code.focus();
  }

  async function passkeyLogin() {
    err.textContent = "";
    try {
      const b = await api("POST", "/api/v1/auth/passkey/login/begin", {});
      const cred = await getAssertion(b.options);
      finishLogin(await api("POST", "/api/v1/auth/passkey/login/finish", { challenge_id: b.challenge_id, credential: cred }), bootstrap);
    } catch (ex) { err.textContent = ex.message || "Passkey sign-in failed"; }
  }
  mount(root, form);
  ident.focus();
}

export function registerView(root) {
  const handle = h("input", { autocomplete: "username", required: true, minlength: 3, maxlength: 30, pattern: "[A-Za-z0-9_]{3,30}" });
  const email = h("input", { type: "email", autocomplete: "email", required: true, maxlength: 254 });
  const pw = h("input", { type: "password", autocomplete: "new-password", required: true, minlength: 12, maxlength: 256 });
  const status = h("p", { class: "muted small", role: "status" });
  const err = h("p", { class: "error", role: "alert" });
  const submit = h("button", { type: "submit", class: "primary" }, "Create account");
  const form = h("form", { class: "card narrow" }, h("h1", null, "Create your account"),
    field("Handle", handle, "3–30 letters, digits or underscores"), field("Email", email),
    field("Password", pw, "At least 12 characters. A passphrase of several words works well."),
    status, err, submit, h("p", { class: "muted small" }, navLink("/login", "Already have an account?")));
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    err.textContent = "";
    submit.disabled = true;
    try {
      status.textContent = "Preparing a quick anti-spam check…";
      const ch = await get("/api/v1/auth/pow");
      const nonce = await solvePow(ch.challenge, ch.difficulty, (n) => { status.textContent = `Anti-spam check… ${n.toLocaleString()}`; });
      status.textContent = "Creating account…";
      await api("POST", "/api/v1/auth/register", { handle: handle.value, email: email.value, password: pw.value,
                                                  pow: { challenge: ch.challenge, nonce } });
      pw.value = "";
      mount(root, h("div", { class: "card narrow" }, h("h1", null, "Check your email"),
        h("p", null, "If the address can be used, we've sent a verification link. You can sign in now; posting unlocks after verification."),
        navLink("/login", "Go to sign in")));
    } catch (ex) { err.textContent = ex.message; status.textContent = ""; } finally { submit.disabled = false; }
  });
  mount(root, form);
}

function fragmentToken() {
  const m = /[#&]token=([A-Za-z0-9_\-%]+)/.exec(location.hash);
  // Remove the token from the address bar/history immediately.
  history.replaceState(null, "", location.pathname);
  return m ? decodeURIComponent(m[1]) : null;
}

export async function verifyEmailView(root) {
  const token = fragmentToken();
  mount(root, h("div", { class: "card narrow" }, h("p", null, "Verifying…")));
  if (!token) return mount(root, h("div", { class: "card narrow" }, h("p", { class: "error" }, "Missing or invalid link.")));
  try {
    await api("POST", "/api/v1/auth/verify-email", { token });
    if (state.user) state.user.email_verified = true;
    mount(root, h("div", { class: "card narrow" }, h("h1", null, "Email verified ✓"), navLink("/", "Continue")));
  } catch (e) {
    mount(root, h("div", { class: "card narrow" }, h("p", { class: "error" }, e.message)));
  }
}

export function resetView(root) {
  const token = location.hash.includes("token=") ? fragmentToken() : null;
  const err = h("p", { class: "error", role: "alert" });
  if (!token) {
    const email = h("input", { type: "email", autocomplete: "email", required: true, maxlength: 254 });
    const f = h("form", { class: "card narrow" }, h("h1", null, "Reset password"), field("Email", email), err,
      h("button", { type: "submit", class: "primary" }, "Send reset link"));
    f.addEventListener("submit", async (e) => {
      e.preventDefault();
      try {
        await api("POST", "/api/v1/auth/password-reset/request", { email: email.value });
        mount(root, h("div", { class: "card narrow" }, h("p", null, "If an account exists for that address, a reset link is on its way. It expires in 30 minutes.")));
      } catch (ex) { err.textContent = ex.message; }
    });
    return mount(root, f);
  }
  const pw = h("input", { type: "password", autocomplete: "new-password", required: true, minlength: 12, maxlength: 256 });
  const f = h("form", { class: "card narrow" }, h("h1", null, "Choose a new password"), field("New password", pw), err,
    h("button", { type: "submit", class: "primary" }, "Reset password"));
  f.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      await api("POST", "/api/v1/auth/password-reset/confirm", { token, new_password: pw.value });
      toast("Password reset. All sessions were signed out.");
      navigate("/login", { replace: true });
    } catch (ex) { err.textContent = ex.message; }
  });
  mount(root, f);
}

export { button };
