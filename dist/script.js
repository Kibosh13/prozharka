const revealItems = document.querySelectorAll(".reveal");
const progress = document.querySelector(".scroll-progress span");
const year = document.querySelector("[data-year]");
const header = document.querySelector("[data-header]");
const navLinks = [...document.querySelectorAll(".nav a")];
const trackedSections = navLinks
  .map((link) => document.querySelector(link.getAttribute("href")))
  .filter(Boolean);

year.textContent = new Date().getFullYear();

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
  progress.style.width = `${Math.min(100, Math.max(0, percentage))}%`;
  header.classList.toggle("is-scrolled", window.scrollY > 24);
};

updateProgress();
window.addEventListener("scroll", updateProgress, { passive: true });

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
