const header = document.querySelector("[data-header]");
const menuToggle = document.querySelector("[data-menu-toggle]");
const menu = document.querySelector("[data-menu]");
const briefForm = document.querySelector("[data-brief-form]");
const formStatus = document.querySelector("[data-form-status]");
const serviceSelect = document.querySelector("#service");
const projectField = document.querySelector("#project");
const contactPrefillKey = "gamaservice-contact-prefill";

const closeMenu = () => {
  menu?.classList.remove("open");
  document.body.classList.remove("menu-open");
  menuToggle?.setAttribute("aria-expanded", "false");
};

menuToggle?.addEventListener("click", () => {
  const isOpen = menu?.classList.toggle("open") ?? false;
  document.body.classList.toggle("menu-open", isOpen);
  menuToggle.setAttribute("aria-expanded", String(isOpen));
});

menu?.querySelectorAll("a").forEach((link) => link.addEventListener("click", closeMenu));

const updateHeader = () => header?.classList.toggle("scrolled", window.scrollY > 18);
updateHeader();
window.addEventListener("scroll", updateHeader, { passive: true });

const revealElements = document.querySelectorAll(".reveal");
if ("IntersectionObserver" in window) {
  const observer = new IntersectionObserver(
    (entries, currentObserver) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add("visible");
        currentObserver.unobserve(entry.target);
      });
    },
    { threshold: 0.1 },
  );
  revealElements.forEach((element) => observer.observe(element));
} else {
  revealElements.forEach((element) => element.classList.add("visible"));
}

const applyContactPrefill = ({ service = "", project = "" } = {}) => {
  if (serviceSelect && service) serviceSelect.value = service;
  if (projectField && project && !projectField.value.trim()) projectField.value = project;
};

try {
  const storedPrefill = JSON.parse(sessionStorage.getItem(contactPrefillKey) || "null");
  if (storedPrefill && typeof storedPrefill === "object") applyContactPrefill(storedPrefill);
  sessionStorage.removeItem(contactPrefillKey);
} catch {
  sessionStorage.removeItem(contactPrefillKey);
}

document.addEventListener("click", (event) => {
  if (!(event.target instanceof Element)) return;
  const link = event.target.closest("[data-service-link]");
  if (!(link instanceof HTMLAnchorElement)) return;

  const prefill = {
    service: link.dataset.serviceLink || "",
    project: link.dataset.projectPrefill || "",
  };
  applyContactPrefill(prefill);
  try {
    const destination = new URL(link.href, window.location.href);
    const leavesCurrentPage = destination.pathname !== window.location.pathname
      || destination.search !== window.location.search;
    if (leavesCurrentPage) {
      sessionStorage.setItem(contactPrefillKey, JSON.stringify(prefill));
    } else {
      sessionStorage.removeItem(contactPrefillKey);
    }
  } catch {
    // The form still works when session storage is unavailable.
  }
});

const copyText = async (text) => {
  if (navigator.clipboard && window.isSecureContext) {
    await navigator.clipboard.writeText(text);
    return;
  }

  const textArea = document.createElement("textarea");
  textArea.value = text;
  textArea.className = "clipboard-fallback";
  textArea.setAttribute("aria-hidden", "true");
  textArea.tabIndex = -1;
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
    `Service : ${data.get("service") || "À définir"}`,
    `Budget : ${data.get("budget") || "À définir"}`,
    `Échéance : ${data.get("deadline") || "À définir"}`,
    `Moyen de contact : ${data.get("contact-method") || "Non renseigné"}`,
    "",
    "Mon projet :",
    String(data.get("project") || ""),
  ].join("\n");

  try {
    await copyText(brief);
    const address = document.querySelector("[data-contact-email]")?.textContent?.trim()
      || "support.gamaservice@gmail.com";
    const subject = encodeURIComponent(`Demande de projet - ${data.get("service") || "GamaService"}`);
    window.location.href = `mailto:${address}?subject=${subject}&body=${encodeURIComponent(brief)}`;
    if (formStatus) {
      formStatus.textContent = "Votre messagerie va s’ouvrir. Le brief a aussi été copié.";
      formStatus.classList.add("success");
    }
  } catch {
    if (formStatus) {
      formStatus.textContent = "Votre messagerie va s’ouvrir avec le brief préparé.";
      formStatus.classList.remove("success");
    }
  }
});

document.querySelectorAll("[data-year]").forEach((element) => {
  element.textContent = String(new Date().getFullYear());
});
