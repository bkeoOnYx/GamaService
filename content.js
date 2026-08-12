const textElement = (tag, className, value) => {
  const element = document.createElement(tag);
  if (className) element.className = className;
  element.textContent = value;
  return element;
};

const portfolioServices = {
  minecraft: { label: "Minecraft", formValue: "Minecraft" },
  "garrys-mod": { label: "Garry's Mod", formValue: "Garry's Mod" },
  "sites-web": { label: "Site web", formValue: "Site web" },
  graphisme: { label: "Graphisme", formValue: "Graphisme" },
};

const emptyPortfolioContent = {
  all: {
    kicker: "Vitrine en préparation",
    title: "Les premières réalisations arrivent bientôt.",
    text: "Présentez-nous déjà votre projet : nous vous répondrons avec les premières pistes utiles.",
    button: "Présenter un projet",
    service: "Projet complet",
  },
  minecraft: {
    kicker: "Vitrine en préparation",
    title: "Les premières fiches de plugins arrivent bientôt.",
    text: "Vous pouvez déjà nous présenter le plugin personnalisé dont votre serveur a besoin.",
    button: "Demander un plugin personnalisé",
    service: "Minecraft",
  },
  "garrys-mod": {
    kicker: "Vitrine en préparation",
    title: "Les premiers projets Garry's Mod arrivent bientôt.",
    text: "Présentez-nous votre serveur, son gamemode et les systèmes que vous souhaitez mettre en place.",
    button: "Présenter un serveur",
    service: "Garry's Mod",
  },
  "sites-web": {
    kicker: "Vitrine en préparation",
    title: "Les premiers sites réalisés arrivent bientôt.",
    text: "Nous pouvons déjà cadrer votre futur site, ses contenus et son objectif principal.",
    button: "Présenter un site",
    service: "Site web",
  },
  graphisme: {
    kicker: "Vitrine en préparation",
    title: "Les premières identités visuelles arrivent bientôt.",
    text: "Parlez-nous de votre univers pour préparer une identité graphique qui lui ressemble.",
    button: "Présenter une identité",
    service: "Graphisme",
  },
};

const safeImagePath = (value) => {
  if (typeof value !== "string") return "";
  if (/^(?:assets\/[a-zA-Z0-9/_-]+\.(?:jpg|jpeg|png|webp)|media\.php\?file=[a-f0-9]{32}\.(?:jpg|png|webp))$/.test(value)) return value;
  return "";
};

const safeDiscordUrl = (value) => {
  try {
    const url = new URL(String(value));
    const allowedHosts = ["discord.gg", "discord.com", "www.discord.com"];
    return url.protocol === "https:" && allowedHosts.includes(url.hostname.toLowerCase()) ? url.href : "";
  } catch {
    return "";
  }
};

const safeProjectUrl = (value) => {
  try {
    const url = new URL(String(value));
    return url.protocol === "https:" ? url.href : "";
  } catch {
    return "";
  }
};

const loadContent = async () => {
  for (const url of ["api/content.php", "data/default-content.json"]) {
    try {
      const response = await fetch(url, { headers: { Accept: "application/json" } });
      if (!response.ok) continue;
      return await response.json();
    } catch {
      // Use the static seed when PHP is unavailable in a local preview.
    }
  }
  return null;
};

const configureContactLink = (link, service, project) => {
  link.href = "/contact";
  link.dataset.serviceLink = service;
  link.dataset.projectPrefill = project;
};

const renderEmptyPortfolio = (grid, category) => {
  const content = emptyPortfolioContent[category] || emptyPortfolioContent.all;
  const empty = document.createElement("div");
  empty.className = "empty-state plugin-empty";
  empty.append(
    textElement("p", "example-kicker", content.kicker),
    textElement("h3", "", content.title),
    textElement("p", "", content.text),
  );
  const link = textElement("a", "button button-secondary", content.button);
  configureContactLink(link, content.service, content.text);
  empty.appendChild(link);
  grid.appendChild(empty);
};

const renderPortfolio = (items) => {
  document.querySelectorAll("[data-portfolio-grid]").forEach((grid) => {
    const category = grid.dataset.portfolioGrid || "all";
    const selectedItems = category === "all"
      ? items.slice(0, 3)
      : items.filter((item) => (item.service || "minecraft") === category);
    grid.replaceChildren();

    if (!selectedItems.length) {
      renderEmptyPortfolio(grid, category);
      return;
    }

    selectedItems.forEach((item) => {
      const serviceKey = portfolioServices[item.service] ? item.service : "minecraft";
      const service = portfolioServices[serviceKey];
      const article = document.createElement("article");
      article.className = "plugin-card";

      const imagePath = safeImagePath(item.image);
      if (imagePath) {
        const media = document.createElement("div");
        media.className = "plugin-media";
        const image = document.createElement("img");
        image.src = imagePath;
        image.alt = item.alt || `Aperçu du projet ${item.title || service.label}`;
        image.width = 1600;
        image.height = 1000;
        image.loading = "lazy";
        media.appendChild(image);
        article.appendChild(media);
      }

      const body = document.createElement("div");
      body.className = "plugin-body";
      const topLine = document.createElement("div");
      topLine.className = "plugin-topline";
      topLine.append(
        textElement("p", "example-kicker", item.label || service.label),
        textElement("span", "plugin-status", item.status || "Sur mesure"),
      );
      body.append(
        topLine,
        textElement("h3", "", item.title || `Projet ${service.label}`),
        textElement("p", "plugin-summary", item.summary || ""),
      );

      if (Array.isArray(item.features) && item.features.length) {
        const features = document.createElement("ul");
        features.className = "plugin-features";
        item.features.slice(0, 6).forEach((feature) => features.appendChild(textElement("li", "", String(feature))));
        body.appendChild(features);
      }

      const footer = document.createElement("div");
      footer.className = "plugin-footer";
      footer.appendChild(textElement("span", "plugin-version", item.versions || "Détails sur demande"));
      const actions = document.createElement("div");
      actions.className = "plugin-actions";
      const projectUrl = safeProjectUrl(item.url);
      if (projectUrl) {
        const project = textElement("a", "plugin-project-link", "Voir le site");
        project.href = projectUrl;
        project.target = "_blank";
        project.rel = "noopener noreferrer";
        project.setAttribute("aria-label", `Voir le site ${item.title || service.label} dans un nouvel onglet`);
        actions.appendChild(project);
      }
      const contact = textElement("a", "plugin-contact", "Demander un projet similaire");
      configureContactLink(
        contact,
        service.formValue,
        `Je souhaite discuter d'un projet similaire à « ${item.title || service.label} ».\n\nMon besoin : `,
      );
      actions.appendChild(contact);
      footer.appendChild(actions);
      body.appendChild(footer);
      article.appendChild(body);
      grid.appendChild(article);
    });
  });
};

const renderReviews = (reviews) => {
  document.querySelectorAll("[data-review-grid]").forEach((grid) => {
    grid.replaceChildren();
    if (!reviews.length) {
      grid.appendChild(textElement("p", "empty-state", "Les premiers avis vérifiés seront publiés ici après livraison des projets."));
      return;
    }

    reviews.forEach((review) => {
      const article = document.createElement("article");
      article.className = "review-card";
      const rating = Math.max(1, Math.min(5, Number(review.rating) || 5));
      article.append(
        textElement("p", "review-stars", "★".repeat(rating)),
        textElement("p", "review-quote", `“${review.quote || ""}”`),
        textElement("p", "review-author", `${review.name || "Client"} · ${review.project || "Projet GamaService"}`),
      );
      grid.appendChild(article);
    });
  });
};

loadContent().then((content) => {
  if (!content) return;
  const email = content.contact?.email || "support.gamaservice@gmail.com";
  const discord = safeDiscordUrl(content.contact?.discord) || "https://discord.gg/F9ZGFUUC9V";
  document.querySelectorAll("[data-contact-email]").forEach((element) => {
    element.textContent = email;
    if (element instanceof HTMLAnchorElement) element.href = `mailto:${email}`;
  });
  document.querySelectorAll("[data-contact-discord]").forEach((element) => {
    if (element instanceof HTMLAnchorElement) element.href = discord;
  });
  renderPortfolio(Array.isArray(content.plugins) ? content.plugins : []);
  renderReviews(Array.isArray(content.reviews) ? content.reviews : []);
});
