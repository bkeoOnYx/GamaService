const header = document.querySelector("[data-header]");
const menuToggle = document.querySelector("[data-menu-toggle]");
const menu = document.querySelector("[data-menu]");
const briefForm = document.querySelector("[data-brief-form]");
const formStatus = document.querySelector("[data-form-status]");
const serviceSelect = document.querySelector("#service");

const closeMenu = () => {
  menu?.classList.remove("open");
  document.body.classList.remove("menu-open");
  menuToggle?.setAttribute("aria-expanded", "false");
};

menuToggle?.addEventListener("click", () => {
  const isOpen = menu?.classList.toggle("open");
  document.body.classList.toggle("menu-open", isOpen);
  menuToggle.setAttribute("aria-expanded", String(isOpen));
});

menu?.querySelectorAll("a").forEach((link) => {
  link.addEventListener("click", closeMenu);
});

const updateHeader = () => {
  header?.classList.toggle("scrolled", window.scrollY > 18);
};

updateHeader();
window.addEventListener("scroll", updateHeader, { passive: true });

const revealObserver = new IntersectionObserver(
  (entries, observer) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add("visible");
      observer.unobserve(entry.target);
    });
  },
  { threshold: 0.12 },
);

document.querySelectorAll(".reveal").forEach((element) => revealObserver.observe(element));

document.querySelectorAll("[data-service-link]").forEach((link) => {
  link.addEventListener("click", () => {
    if (!serviceSelect) return;
    serviceSelect.value = link.dataset.serviceLink || "";
  });
});

const copyText = async (text) => {
  if (navigator.clipboard && window.isSecureContext) {
    await navigator.clipboard.writeText(text);
    return;
  }

  const textArea = document.createElement("textarea");
  textArea.value = text;
  textArea.style.position = "fixed";
  textArea.style.opacity = "0";
  document.body.appendChild(textArea);
  textArea.select();
  document.execCommand("copy");
  textArea.remove();
};

briefForm?.addEventListener("submit", async (event) => {
  event.preventDefault();

  const data = new FormData(briefForm);
  const brief = [
    "Bonjour GamaService,",
    "",
    `Service : ${data.get("service")}`,
    `Budget : ${data.get("budget")}`,
    `Échéance : ${data.get("deadline")}`,
    "",
    "Mon projet :",
    data.get("project"),
  ].join("\n");

  try {
    await copyText(brief);
    formStatus.textContent = "Brief copié ! Il est prêt à être envoyé à GamaService.";
    formStatus.classList.add("success");
  } catch {
    formStatus.textContent = "La copie a échoué. Sélectionnez le texte et réessayez.";
    formStatus.classList.remove("success");
  }
});

document.querySelectorAll("[data-year]").forEach((element) => {
  element.textContent = String(new Date().getFullYear());
});
