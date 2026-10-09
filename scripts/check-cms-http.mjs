import { readFile } from "node:fs/promises";
import { createInterface } from "node:readline";

const base = (process.argv[2] || "http://127.0.0.1:4173").replace(/\/$/, "");
const reader = createInterface({ input: process.stdin });
const password = String(await new Promise((resolve) => reader.once("line", resolve))).trim();
reader.close();
const cookies = new Map();
if (base === 'https://prozharka-tg.com') cookies.set('beget', 'begetok');
let csrf = "";

async function request(action, options = {}) {
  const response = await fetch(`${base}/admin/?action=${action}`, {
    ...options,
    headers: { Cookie: [...cookies].map(([key, value]) => `${key}=${value}`).join("; "),
      "X-CSRF-Token": csrf, ...(options.body instanceof FormData ? {} : { "Content-Type": "application/json" }), ...options.headers },
  });
  for (const cookie of response.headers.getSetCookie()) {
    const [pair] = cookie.split(";"); const index = pair.indexOf("=");
    cookies.set(pair.slice(0, index), pair.slice(index + 1));
  }
  return { response, data: await response.json() };
}
function expect(condition, message) { if (!condition) throw new Error(message); }

const anonymous = await request("content");
expect(anonymous.response.status === 401, "Anonymous content requests must be denied");
const session = await request("session");
csrf = session.data.csrf;
const login = await request("login", { method: "POST", body: JSON.stringify({ username: "tatyana", password }) });
expect(login.data.ok, "Administrator must be able to sign in"); csrf = login.data.csrf;
const content = await request("content");
expect(content.data.ok && Object.keys(content.data.schema).length > 300, "Authenticated schema must expose all content");
expect(content.response.headers.get("cache-control") === "no-store", "Admin content must not be cached");
expect(content.response.headers.get("x-robots-tag").includes("noindex"), "Admin must be excluded from search");
const denied = await request("prepare-preview", { method: "POST", headers: { "X-CSRF-Token": "invalid" }, body: JSON.stringify({ values: {} }) });
expect(denied.response.status === 422 && !denied.data.ok, "A forged mutation must be denied");

const field = Object.entries(content.data.schema).find(([, item]) => item.type === "image");
const imageForm = new FormData();
const image = await readFile(new URL("../dist/assets/stretch.jpg", import.meta.url));
imageForm.append("image", new Blob([image], { type: "image/jpeg" }), "photo.jpg");
const uploaded = await request("upload", { method: "POST", body: imageForm });
expect(uploaded.data.ok && uploaded.data.path.endsWith(".webp"), "Photo must be decoded and converted to WebP");
const imageResponse = await fetch(new URL(uploaded.data.path, `${base}/`));
expect(imageResponse.ok && imageResponse.headers.get("content-type").includes("image/webp"), "Uploaded image must be publicly served");
const invalidForm = new FormData();
invalidForm.append("image", new Blob(["<?php echo 'not-an-image'; ?>"], { type: "image/jpeg" }), "photo.jpg");
const invalid = await request("upload", { method: "POST", body: invalidForm });
expect(!invalid.data.ok, "Executable content disguised as a photograph must be rejected");

const previewValues = { ...content.data.content.values, [field[0]]: uploaded.data.path };
const preview = await request("prepare-preview", { method: "POST", body: JSON.stringify({ values: previewValues }) });
expect(preview.data.ok, "Preview must accept an uploaded photograph");
const response = await fetch(`${base}/admin/?action=preview&page=main`, {
  headers: { Cookie: [...cookies].map(([key, value]) => `${key}=${value}`).join("; ") },
});
const html = await response.text();
expect(response.ok && html.includes(uploaded.data.path), "Photo must appear in the rendered preview");
expect(html.includes("data-checkout-preview") && !html.includes("data-checkout-form"), "Preview must not open a real checkout");
const untouched = await request("content");
expect(untouched.data.content.revision === content.data.content.revision, "Preview must not publish content");

await request("logout", { method: "POST", body: "{}" });
const loggedOut = await request("content");
expect(loggedOut.response.status === 401, "Logout must revoke access");
console.log(JSON.stringify({ ok: true, authenticated: true, csrf_verified: true,
  real_image_upload: true, unsafe_upload_rejected: true, preview_did_not_publish: true,
  logout_verified: true, uploaded_test_image: uploaded.data.path }));
