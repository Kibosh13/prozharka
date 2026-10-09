"use strict";

const $ = (selector) => document.querySelector(selector);
const state = { csrf: "", schema: {}, values: {}, baseline: "", revision: "initial", history: [], section: "Обложка", busy: false };
const groupOrder = ["Обложка", "Меню", "Подход", "Что внутри", "Библиотека", "Результаты", "О Татьяне", "Вопросы", "Участие", "Подвал", "Контакты", "SEO", "Cookies", "Подтверждение оплаты", "Оплата · сообщения", "Политика обработки данных", "Согласие на обработку данных", "Оферта", "Общие тексты", "История изменений", "Пароль"];
const descriptions = {
  "SEO": "Заголовки и описания для поиска, обложка для мессенджеров и индексация боевого сайта. Демоверсия GitHub остаётся закрытой от индексации.",
  "Контакты": "Заполненные контакты показываются внизу главной страницы. Пустые поля скрыты.",
  "Участие": "Изменение стоимости обновляет обе цены на сайте и сумму новых заказов. Ранее созданные заказы сохраняют свою цену.",
  "Результаты": "Можно заменить каждую фотографию и настроить её кадрирование.",
  "Политика обработки данных": "Редактируется текст веб-страницы. Исходный DOCX по ссылке скачивания остаётся исходным документом.",
  "Согласие на обработку данных": "Редактируется текст веб-страницы. Исходный DOCX по ссылке скачивания остаётся исходным документом.",
  "Оферта": "Редактируется текст веб-страницы. Исходный DOCX по ссылке скачивания остаётся исходным документом.",
};

const api = async (action, options = {}) => {
  const response = await fetch(`./?action=${encodeURIComponent(action)}`, {
    credentials: "same-origin", cache: "no-store", ...options,
    headers: { "X-CSRF-Token": state.csrf, ...(options.body instanceof FormData ? {} : { "Content-Type": "application/json" }), ...options.headers },
  });
  const data = await response.json();
  if (!response.ok || !data.ok) {
    if (response.status === 401 && action !== "login") showLogin();
    throw new Error(data.error || "Не удалось выполнить действие");
  }
  return data;
};
const post = (action, data) => api(action, { method: "POST", body: JSON.stringify(data) });
const overrides = () => Object.fromEntries(Object.entries(state.values).filter(([key, value]) => value !== state.schema[key]?.default));
const isDirty = () => JSON.stringify(overrides()) !== state.baseline;

const message = (text, error = false) => {
  $("#editor-message").textContent = text;
  $("#editor-message").classList.toggle("error-text", error);
};
const updateDirty = () => {
  const dirty = isDirty();
  $("#save-state").textContent = dirty ? "Есть изменения" : "Всё сохранено";
  $("#save-state").classList.toggle("dirty", dirty);
  $("#save-button").disabled = state.busy || !dirty;
};
const busy = (value) => {
  state.busy = value;
  $("#preview-button").disabled = value;
  updateDirty();
};
const updateValue = (key, value) => { state.values[key] = value; updateDirty(); };
const element = (tag, className, text) => {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
};

function textField(key, definition) {
  const label = element("label", "", definition.label);
  const value = state.values[key] ?? definition.default;
  const multiline = definition.type === "text" && (value.length > 65 || value.includes("\n"));
  const input = element(multiline ? "textarea" : "input");
  input.value = value;
  input.dataset.key = key;
  if (multiline) input.rows = Math.min(10, Math.max(3, Math.ceil(value.length / 90)));
  else input.type = ({ email: "email", phone: "tel", price: "number", url: "url" })[definition.type] || "text";
  if (definition.type === "price") { input.min = "1"; input.max = "1000000"; input.step = "1"; }
  input.addEventListener("input", () => updateValue(key, input.value));
  label.append(input);
  if (multiline) label.append(element("p", "field-note", "Перенос строки переносит текст и на сайте."));
  return label;
}

function imageField(key, definition) {
  const panel = element("section", "panel");
  panel.append(element("p", "field-title", definition.label));
  const layout = element("div", "image-editor");
  const image = element("img", "image-preview");
  image.src = new URL(state.values[key], `${location.origin}/`).href;
  image.alt = definition.label;
  const positionKey = `${key}.position`;
  const fitKey = `${key}.fit`;
  image.style.objectPosition = state.values[positionKey] || "50% 50%";
  image.style.objectFit = state.values[fitKey] || "contain";
  const controls = element("div", "image-controls");
  const uploadLabel = element("label", "button secondary", "Заменить фото");
  const upload = element("input", "file-input");
  upload.type = "file"; upload.accept = "image/jpeg,image/png,image/webp";
  uploadLabel.append(upload);
  const uploadNote = element("p", "field-note", "JPG, PNG или WebP · до 10 МБ");
  upload.addEventListener("change", async () => {
    if (!upload.files[0]) return;
    if (upload.files[0].size > 10 * 1024 * 1024) { message("Фотография должна быть не больше 10 МБ.", true); return; }
    const data = new FormData(); data.append("image", upload.files[0]);
    upload.disabled = true; uploadNote.textContent = "Загружаем фотографию…"; busy(true);
    try {
      const result = await api("upload", { method: "POST", body: data });
      updateValue(key, result.path);
      image.src = new URL(result.path, `${location.origin}/`).href;
      uploadNote.textContent = "Фото загружено. Сохраните изменения, чтобы оно появилось на сайте.";
      message("Новое фото готово. Проверьте кадрирование и сохраните изменения.");
    } catch (error) { uploadNote.textContent = error.message; message(error.message, true); }
    finally { upload.disabled = false; busy(false); }
  });
  controls.append(uploadLabel, uploadNote);
  if (state.schema[`${key}.alt`]) controls.append(textField(`${key}.alt`, state.schema[`${key}.alt`]));
  if (state.schema[fitKey]) {
    const label = element("label", "", "Как показать фотографию");
    const select = element("select");
    for (const [value, title] of [["cover", "Заполнить карточку"], ["contain", "Показать весь кадр"]]) {
      const option = element("option", "", title); option.value = value; select.append(option);
    }
    select.value = state.values[fitKey];
    select.addEventListener("change", () => { updateValue(fitKey, select.value); image.style.objectFit = select.value; });
    label.append(select); controls.append(label);
  }
  if (state.schema[positionKey]) {
    const values = state.values[positionKey].match(/\d+/g).map(Number);
    ["По горизонтали", "По вертикали"].forEach((title, index) => {
      const label = element("label");
      const row = element("div", "range-row");
      const output = element("output", "", `${values[index]}%`);
      row.append(element("span", "", title), output);
      const range = element("input"); range.type = "range"; range.min = "0"; range.max = "100"; range.value = values[index];
      range.addEventListener("input", () => {
        values[index] = Number(range.value); output.textContent = `${range.value}%`;
        const position = `${values[0]}% ${values[1]}%`; updateValue(positionKey, position); image.style.objectPosition = position;
      });
      label.append(row, range); controls.append(label);
    });
  }
  layout.append(image, controls); panel.append(layout);
  return panel;
}

function renderSection(group) {
  state.section = group;
  $("#section-title").textContent = group;
  $("#section-description").textContent = descriptions[group] || "Все правки в разделах сохраняются вместе. Перед публикацией можно посмотреть сайт на компьютере и телефоне.";
  document.querySelectorAll("#section-nav button").forEach((button) => button.setAttribute("aria-current", String(button.textContent === group)));
  $("#fields").replaceChildren();
  $("#history-panel").hidden = group !== "История изменений";
  $("#password-form").hidden = group !== "Пароль";
  $("#content-form").hidden = ["История изменений", "Пароль"].includes(group);
  for (const [key, definition] of Object.entries(state.schema)) {
    if (definition.group !== group || ["position", "fit"].includes(definition.type)
        || (key.endsWith(".alt") && state.schema[key.slice(0, -4)]?.type === "image")) continue;
    if (definition.type === "image") { $("#fields").append(imageField(key, definition)); continue; }
    const panel = element("div", "panel");
    if (definition.type === "checkbox") {
      const label = element("label", "check-label");
      const input = element("input"); input.type = "checkbox"; input.checked = state.values[key] === "1";
      input.addEventListener("change", () => updateValue(key, input.checked ? "1" : "0"));
      label.append(input, element("span", "", definition.label)); panel.append(label);
    } else panel.append(textField(key, definition));
    $("#fields").append(panel);
  }
  if (group === "История изменений") renderHistory();
}

function applyContent(content, history) {
  state.revision = content.revision;
  state.values = Object.fromEntries(Object.entries(state.schema).map(([key, definition]) => [key, content.values[key] ?? definition.default]));
  state.baseline = JSON.stringify(overrides());
  state.history = history || [];
  updateDirty();
}

function renderHistory() {
  const list = $("#history-list"); list.replaceChildren();
  if (!state.history.length) list.append(element("p", "panel muted", "Предыдущие версии появятся после первого сохранения."));
  state.history.forEach((item) => {
    const row = element("div", "panel history-row");
    const date = item.updated_at ? new Date(item.updated_at).toLocaleString("ru-RU", { timeZone: "Europe/Moscow" }) : "Исходная версия сайта";
    const button = element("button", "button secondary", "Восстановить"); button.type = "button";
    button.addEventListener("click", async () => {
      if (!confirm(`Восстановить версию «${date}»? Текущая версия сохранится в истории.`)) return;
      button.disabled = true; busy(true);
      try {
        const result = await post("restore", { target: item.revision, revision: state.revision });
        applyContent(result.content, result.history); renderHistory(); message("Предыдущая версия восстановлена на сайте.");
      } catch (error) { message(error.message, true); }
      finally { button.disabled = false; busy(false); }
    });
    row.append(element("p", "", date), button); list.append(row);
  });
}

async function loadEditor() {
  const result = await api("content");
  state.schema = result.schema;
  applyContent(result.content, result.history);
  const groups = new Set(Object.values(state.schema).map((field) => field.group));
  const nav = $("#section-nav"); nav.replaceChildren();
  groupOrder.filter((group) => groups.has(group) || ["История изменений", "Пароль"].includes(group)).forEach((group) => {
    const button = element("button", "", group); button.type = "button";
    button.addEventListener("click", () => renderSection(group)); nav.append(button);
  });
  const pageSelect = $("#preview-page"); pageSelect.replaceChildren();
  for (const [key, page] of Object.entries(result.pages)) {
    const option = element("option", "", page.label); option.value = key; pageSelect.append(option);
  }
  $("#boot-message").hidden = true; $("#login-view").hidden = true; $("#editor-view").hidden = false;
  renderSection(state.section);
}

function showLogin() {
  $("#boot-message").hidden = true; $("#editor-view").hidden = true; $("#login-view").hidden = false;
}

$("#login-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const form = event.currentTarget; const button = form.querySelector("button"); button.disabled = true;
  $("#login-message").textContent = "";
  try {
    const result = await post("login", Object.fromEntries(new FormData(form)));
    state.csrf = result.csrf; form.reset(); await loadEditor();
  } catch (error) { $("#login-message").textContent = error.message; }
  finally { button.disabled = false; }
});
$("#content-form").addEventListener("submit", (event) => event.preventDefault());
$("#save-button").addEventListener("click", async () => {
  if (state.busy || !isDirty()) return;
  if (!$("#content-form").reportValidity()) return;
  busy(true); message("Сохраняем изменения…");
  try {
    const result = await post("save", { revision: state.revision, values: overrides() });
    applyContent(result.content, result.history); message("Сохранено. Изменения уже на сайте.");
  } catch (error) { message(error.message, true); }
  finally { busy(false); }
});
$("#preview-button").addEventListener("click", async () => {
  busy(true);
  try {
    await post("prepare-preview", { values: overrides() });
    $("#preview-page").value = "main";
    $("#preview-frame").src = `./?action=preview&page=main&v=${Date.now()}`;
    $("#preview-dialog").showModal(); message("Предпросмотр открыт. Изменения ещё не опубликованы.");
  } catch (error) { message(error.message, true); }
  finally { busy(false); }
});
$("#preview-page").addEventListener("change", (event) => { $("#preview-frame").src = `./?action=preview&page=${encodeURIComponent(event.target.value)}&v=${Date.now()}`; });
$("#preview-width").addEventListener("click", () => { const mobile = $("#preview-frame").classList.toggle("mobile"); $("#preview-width").textContent = mobile ? "Компьютер" : "Телефон"; });
$("#preview-close").addEventListener("click", () => $("#preview-dialog").close());
$("#logout-button").addEventListener("click", async () => {
  if (isDirty() && !confirm("Есть несохранённые изменения. Выйти из редактора?")) return;
  try { await post("logout", {}); location.reload(); } catch (error) { message(error.message, true); }
});
$("#password-form").addEventListener("submit", async (event) => {
  event.preventDefault(); const form = event.currentTarget; const data = Object.fromEntries(new FormData(form));
  if (data.password !== data.confirmation) { message("Новые пароли не совпадают.", true); return; }
  const button = form.querySelector("button"); button.disabled = true;
  try { await post("password", data); form.reset(); message("Пароль изменён."); }
  catch (error) { message(error.message, true); }
  finally { button.disabled = false; }
});
window.addEventListener("beforeunload", (event) => { if (isDirty()) { event.preventDefault(); event.returnValue = ""; } });

(async () => {
  try {
    const session = await api("session"); state.csrf = session.csrf;
    if (session.authenticated) await loadEditor(); else showLogin();
  } catch (error) { $("#boot-message").textContent = error.message; }
})();
