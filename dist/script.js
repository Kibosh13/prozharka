const revealItems = document.querySelectorAll(".reveal");
const progress = document.querySelector(".scroll-progress span");
const year = document.querySelector("[data-year]");
const header = document.querySelector("[data-header]");
const navLinks = [...document.querySelectorAll(".nav a")];
const trackedSections = navLinks
  .map((link) => document.querySelector(link.getAttribute("href")))
  .filter(Boolean);

if (year) year.textContent = new Date().getFullYear();

if ("IntersectionObserver" in window) {
  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add("is-visible");
          observer.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.12, rootMargin: "0px 0px -40px" },
  );

  revealItems.forEach((item) => observer.observe(item));
} else {
  revealItems.forEach((item) => item.classList.add("is-visible"));
}

const updateProgress = () => {
  const scrollable = document.documentElement.scrollHeight - window.innerHeight;
  const percentage = scrollable > 0 ? (window.scrollY / scrollable) * 100 : 0;
  if (progress) progress.style.width = `${Math.min(100, Math.max(0, percentage))}%`;
  if (header) header.classList.toggle("is-scrolled", window.scrollY > 24);
};

if (progress || header) {
  updateProgress();
  window.addEventListener("scroll", updateProgress, { passive: true });
}

if ("IntersectionObserver" in window) {
  const sectionObserver = new IntersectionObserver(
    (entries) => {
      const visible = entries
        .filter((entry) => entry.isIntersecting)
        .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];

      if (!visible) return;

      navLinks.forEach((link) => {
        const active = link.getAttribute("href") === `#${visible.target.id}`;
        if (active) link.setAttribute("aria-current", "true");
        else link.removeAttribute("aria-current");
      });
    },
    { threshold: [0.01], rootMargin: "-18% 0px -70%" },
  );

  trackedSections.forEach((section) => sectionObserver.observe(section));
}

const checkoutForm = document.querySelector("[data-checkout-form]");

const cookieConsentName = "prozharka_cookie_consent";
const cookieConsentVersion = "1";

const readCookieConsent = () => {
  const prefix = `${cookieConsentName}=`;
  const value = document.cookie
    .split(";")
    .map((item) => item.trim())
    .find((item) => item.startsWith(prefix));
  if (!value) return "unset";
  const decoded = decodeURIComponent(value.slice(prefix.length));
  return ["all", "necessary"].includes(decoded) ? decoded : "unset";
};

const saveCookieConsent = (choice) => {
  const secure = window.location.protocol === "https:" ? "; Secure" : "";
  document.cookie = `${cookieConsentName}=${encodeURIComponent(choice)}; Max-Age=15552000; Path=/; SameSite=Lax${secure}`;
  window.localStorage.setItem(`${cookieConsentName}_version`, cookieConsentVersion);
  document.documentElement.dataset.cookieConsent = choice;
};

const siteRoot = document.body.dataset.siteRoot || "./";
let cookiePanel;

const hideCookiePanel = () => {
  if (!cookiePanel) return;
  cookiePanel.classList.remove("is-visible");
  window.setTimeout(() => {
    cookiePanel.hidden = true;
  }, 250);
};

const showCookiePanel = () => {
  if (!cookiePanel) return;
  cookiePanel.hidden = false;
  window.requestAnimationFrame(() => cookiePanel.classList.add("is-visible"));
};

const buildCookiePanel = () => {
  cookiePanel = document.createElement("section");
  cookiePanel.className = "cookie-consent";
  cookiePanel.hidden = true;
  cookiePanel.setAttribute("role", "dialog");
  cookiePanel.setAttribute("aria-labelledby", "cookie-consent-title");
  cookiePanel.setAttribute("aria-describedby", "cookie-consent-copy");
  cookiePanel.innerHTML = `
    <div class="cookie-consent__copy">
      <p class="cookie-consent__eyebrow">Конфиденциальность</p>
      <h2 id="cookie-consent-title">Настройки cookies</h2>
      <p id="cookie-consent-copy">
        Сайт использует необходимые cookies для сохранения выбранных настроек. Аналитические cookies
        могут использоваться только после вашего согласия. Сейчас аналитические сервисы не подключены.
      </p>
      <nav aria-label="Документы о конфиденциальности">
        <a href="${siteRoot}privacy/">Политика обработки данных</a>
        <a href="${siteRoot}consent/">Согласие на обработку данных</a>
        <a href="${siteRoot}offer/">Публичная оферта</a>
      </nav>
    </div>
    <div class="cookie-consent__actions">
      <button class="cookie-choice cookie-choice--secondary" type="button" data-cookie-choice="necessary">Только необходимые</button>
      <button class="cookie-choice" type="button" data-cookie-choice="all">Принять</button>
    </div>`;
  document.body.append(cookiePanel);

  cookiePanel.querySelectorAll("[data-cookie-choice]").forEach((button) => {
    button.addEventListener("click", () => {
      saveCookieConsent(button.dataset.cookieChoice);
      hideCookiePanel();
    });
  });

  document.querySelectorAll("[data-cookie-settings]").forEach((button) => {
    button.addEventListener("click", showCookiePanel);
  });

  const currentChoice = readCookieConsent();
  if (currentChoice === "unset") showCookiePanel();
  else document.documentElement.dataset.cookieConsent = currentChoice;
};

buildCookiePanel();

if (checkoutForm) {
  const checkoutButton = checkoutForm.querySelector("button[type='submit']");
  const checkoutStatus = checkoutForm.querySelector("[data-checkout-status]");

  checkoutForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    if (!checkoutForm.reportValidity()) return;

    checkoutButton.disabled = true;
    checkoutButton.textContent = "Готовим оплату…";
    checkoutStatus.textContent = "";

    const form = new FormData(checkoutForm);
    const payload = {
      full_name: form.get("full_name"),
      phone: form.get("phone"),
      email: form.get("email"),
      telegram_username: form.get("telegram_username"),
      personal_data_consent: form.get("personal_data_consent") === "on",
      offer_acceptance: form.get("offer_acceptance") === "on",
      cookie_choice: readCookieConsent(),
    };

    try {
      const response = await fetch("./api/checkout", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await response.json();
      if (!response.ok || !data.payment_url) {
        throw new Error(data.error || "Не удалось открыть оплату");
      }
      window.location.assign(data.payment_url);
    } catch (error) {
      checkoutStatus.textContent = error.message || "Временная ошибка. Попробуйте ещё раз.";
      checkoutButton.disabled = false;
      checkoutButton.textContent = "Перейти к оплате";
    }
  });
}

const paymentStatus = document.querySelector("[data-payment-status]");

if (paymentStatus) {
  const orderToken = new URLSearchParams(window.location.search).get("order");
  const title = paymentStatus.querySelector("[data-payment-title]");
  const message = paymentStatus.querySelector("[data-payment-message]");
  const loader = paymentStatus.querySelector("[data-payment-loader]");
  const invite = paymentStatus.querySelector("[data-payment-invite]");
  let attempts = 0;

  const showError = (text) => {
    title.innerHTML = "Нужна<br />проверка.";
    message.textContent = text;
    loader.hidden = true;
  };

  const pollOrder = async () => {
    if (!orderToken) {
      showError("Не найден номер заказа. Вернитесь на сайт и повторите оформление.");
      return;
    }

    try {
      const response = await fetch(`./api/orders/${encodeURIComponent(orderToken)}`, {
        headers: { Accept: "application/json" },
        cache: "no-store",
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Заказ не найден");

      if (data.status === "ready" && data.invite_link) {
        title.innerHTML = "Добро<br />пожаловать.";
        message.textContent = "Оплата подтверждена. Персональная ссылка готова — она рассчитана на одного участника.";
        invite.href = data.invite_link;
        invite.hidden = false;
        loader.hidden = true;
        return;
      }

      if (data.status === "preparing_access") {
        title.innerHTML = "Оплата<br />получена.";
        message.textContent = "Создаём персональную ссылку в Telegram-канал.";
      }

      attempts += 1;
      if (attempts < 120) {
        window.setTimeout(pollOrder, 2500);
      } else {
        showError("Оплата получена, но ссылка задерживается. Напишите в поддержку и укажите email из заказа.");
      }
    } catch (error) {
      attempts += 1;
      if (attempts < 12) {
        window.setTimeout(pollOrder, 2500);
      } else {
        showError(error.message || "Не удалось проверить оплату.");
      }
    }
  };

  pollOrder();
}
