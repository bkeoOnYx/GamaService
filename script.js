const header = document.querySelector("[data-header]");
const menuToggle = document.querySelector("[data-menu-toggle]");
const menu = document.querySelector("[data-menu]");
const briefForm = document.querySelector("[data-brief-form]");
const formStatus = document.querySelector("[data-form-status]");
const serviceSelect = document.querySelector("#service");
const projectField = document.querySelector("#project");
const contactPrefillKey = "gamaservice-contact-prefill";
const customSelectControllers = [];

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

const closeCustomSelects = (except = null) => {
  customSelectControllers.forEach((controller) => {
    if (controller !== except) controller.close();
  });
};

const enhanceSelect = (select) => {
  const field = select.closest(".field-group");
  const label = field?.querySelector(`label[for="${select.id}"]`);
  if (!field || !label || select.dataset.enhanced === "true") return;

  select.dataset.enhanced = "true";
  select.classList.add("custom-select-native");
  select.tabIndex = -1;
  select.setAttribute("aria-hidden", "true");

  const wrapper = document.createElement("div");
  wrapper.className = "custom-select";
  select.before(wrapper);
  wrapper.append(select);

  const labelId = `${select.id}-label`;
  const valueId = `${select.id}-value`;
  const listboxId = `${select.id}-listbox`;
  label.id = labelId;

  const trigger = document.createElement("button");
  trigger.type = "button";
  trigger.className = "custom-select-trigger";
  trigger.setAttribute("aria-haspopup", "listbox");
  trigger.setAttribute("aria-expanded", "false");
  trigger.setAttribute("aria-controls", listboxId);
  trigger.setAttribute("aria-labelledby", `${labelId} ${valueId}`);

  const value = document.createElement("span");
  value.id = valueId;
  value.className = "custom-select-value";
  const chevron = document.createElement("span");
  chevron.className = "custom-select-chevron";
  chevron.setAttribute("aria-hidden", "true");
  chevron.textContent = "⌄";
  trigger.append(value, chevron);

  const listbox = document.createElement("div");
  listbox.id = listboxId;
  listbox.className = "custom-select-menu";
  listbox.setAttribute("role", "listbox");
  listbox.setAttribute("aria-labelledby", labelId);
  listbox.hidden = true;

  const optionButtons = Array.from(select.options)
    .filter((option) => option.value)
    .map((option, index) => {
      const button = document.createElement("button");
      button.type = "button";
      button.id = `${select.id}-option-${index}`;
      button.className = "custom-select-option";
      button.setAttribute("role", "option");
      button.dataset.value = option.value;
      button.textContent = option.textContent;
      listbox.append(button);
      return button;
    });

  wrapper.append(trigger, listbox);
  let activeIndex = 0;

  const setActive = (index) => {
    activeIndex = Math.max(0, Math.min(index, optionButtons.length - 1));
    optionButtons.forEach((button, buttonIndex) => {
      button.classList.toggle("active", buttonIndex === activeIndex);
    });
    const activeOption = optionButtons[activeIndex];
    if (activeOption) {
      trigger.setAttribute("aria-activedescendant", activeOption.id);
      activeOption.scrollIntoView({ block: "nearest" });
    }
  };

  const close = () => {
    wrapper.classList.remove("open");
    trigger.setAttribute("aria-expanded", "false");
    trigger.removeAttribute("aria-activedescendant");
    listbox.hidden = true;
  };

  const open = () => {
    closeCustomSelects(controller);
    wrapper.classList.add("open");
    trigger.setAttribute("aria-expanded", "true");
    listbox.hidden = false;
    const selectedIndex = optionButtons.findIndex((button) => button.dataset.value === select.value);
    setActive(selectedIndex >= 0 ? selectedIndex : 0);
  };

  const sync = () => {
    const selectedOption = select.options[select.selectedIndex];
    value.textContent = selectedOption?.textContent || select.options[0]?.textContent || "Choisir";
    wrapper.classList.toggle("has-value", Boolean(select.value));
    trigger.removeAttribute("aria-invalid");
    optionButtons.forEach((button) => {
      button.setAttribute("aria-selected", String(button.dataset.value === select.value));
    });
  };

  const choose = (button) => {
    select.value = button.dataset.value || "";
    select.dispatchEvent(new Event("change", { bubbles: true }));
    close();
    trigger.focus();
  };

  const controller = { close, open, select, trigger };
  customSelectControllers.push(controller);
  sync();

  trigger.addEventListener("click", () => {
    if (listbox.hidden) open();
    else close();
  });

  trigger.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      close();
      return;
    }
    if (event.key === "Tab") {
      close();
      return;
    }
    if (["ArrowDown", "ArrowUp", "Home", "End", "Enter", " "].includes(event.key)) {
      event.preventDefault();
    }
    if (listbox.hidden) {
      if (["ArrowDown", "ArrowUp", "Enter", " "].includes(event.key)) open();
      return;
    }
    if (event.key === "ArrowDown") setActive(activeIndex + 1);
    if (event.key === "ArrowUp") setActive(activeIndex - 1);
    if (event.key === "Home") setActive(0);
    if (event.key === "End") setActive(optionButtons.length - 1);
    if (["Enter", " "].includes(event.key) && optionButtons[activeIndex]) {
      choose(optionButtons[activeIndex]);
    }
  });

  optionButtons.forEach((button, index) => {
    button.addEventListener("pointerenter", () => setActive(index));
    button.addEventListener("click", () => choose(button));
  });
  label.addEventListener("click", (event) => {
    event.preventDefault();
    trigger.focus();
  });
  select.addEventListener("change", sync);
  select.addEventListener("invalid", (event) => {
    event.preventDefault();
    trigger.setAttribute("aria-invalid", "true");
    if (!briefForm?.querySelector('.custom-select-trigger[aria-invalid="true"]:focus')) {
      trigger.focus();
      open();
    }
  });
  briefForm?.addEventListener("reset", () => requestAnimationFrame(sync));
};

document.querySelectorAll(".brief-form select").forEach(enhanceSelect);
document.addEventListener("pointerdown", (event) => {
  if (!(event.target instanceof Element) || !event.target.closest(".custom-select")) {
    closeCustomSelects();
  }
});
briefForm?.addEventListener("click", (event) => {
  if (!(event.target instanceof Element) || !event.target.closest('[type="submit"]')) return;
  const firstMissing = customSelectControllers.find(({ select }) => select.required && !select.value);
  if (!firstMissing) return;

  event.preventDefault();
  customSelectControllers.forEach(({ select, trigger }) => {
    if (select.required && !select.value) trigger.setAttribute("aria-invalid", "true");
    else trigger.removeAttribute("aria-invalid");
  });
  firstMissing.trigger.focus();
  firstMissing.open();
}, true);

const applyContactPrefill = ({ service = "", project = "" } = {}) => {
  if (serviceSelect && service) {
    serviceSelect.value = service;
    serviceSelect.dispatchEvent(new Event("change", { bubbles: true }));
  }
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
