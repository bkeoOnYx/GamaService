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

briefForm?.addEventListener("submit", async (event) => {
  event.preventDefault();
  const submitButton = briefForm.querySelector("[type='submit']");
  if (!(submitButton instanceof HTMLButtonElement) || submitButton.disabled) return;

  const initialLabel = submitButton.textContent;
  submitButton.disabled = true;
  submitButton.setAttribute("aria-busy", "true");
  submitButton.textContent = "Envoi en cours...";
  formStatus?.classList.remove("success", "error");
  if (formStatus) formStatus.textContent = "Envoi sécurisé de votre demande...";

  try {
    const response = await fetch(briefForm.action, {
      method: "POST",
      headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
      body: new FormData(briefForm),
      credentials: "same-origin",
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error(payload.message || "L’envoi a échoué. Réessayez dans quelques minutes.");
    }

    briefForm.reset();
    if (formStatus) {
      formStatus.textContent = payload.message || "Votre demande a bien été envoyée.";
      formStatus.classList.add("success");
    }
  } catch (error) {
    if (formStatus) {
      formStatus.textContent = error instanceof Error
        ? error.message
        : "L’envoi a échoué. Réessayez dans quelques minutes.";
      formStatus.classList.add("error");
    }
  } finally {
    submitButton.disabled = false;
    submitButton.removeAttribute("aria-busy");
    submitButton.textContent = initialLabel;
  }
});

document.querySelectorAll("[data-year]").forEach((element) => {
  element.textContent = String(new Date().getFullYear());
});
