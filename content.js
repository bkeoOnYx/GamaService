const textElement = (tag, className, value) => {
  const element = document.createElement(tag);
  if (className) element.className = className;
  element.textContent = value;
  return element;
};

const safeImagePath = (value) => {
  if (typeof value !== "string") return "";
  if (/^(?:assets\/[a-zA-Z0-9/_-]+\.(?:jpg|jpeg|png|webp)|media\.php\?file=[a-f0-9]{32}\.(?:jpg|png|webp))$/.test(value)) return value;
  return "";
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

const pluginContactLink = (email, title) => {
  const subject = `Demande de plugin Minecraft personnalisé - ${title}`;
  const body = `Bonjour GamaService,\n\nJ'ai découvert le plugin « ${title} » dans votre portfolio. Je souhaite discuter d'un plugin personnalisé pour mon projet.\n\nMon besoin :\n`;
  return `mailto:${email}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
};

const renderPlugins = (plugins, email) => {
  document.querySelectorAll("[data-plugin-grid]").forEach((grid) => {
    grid.replaceChildren();

    if (!plugins.length) {
      const empty = document.createElement("div");
      empty.className = "empty-state plugin-empty";
      empty.append(
        textElement("p", "example-kicker", "Portfolio en préparation"),
        textElement("h3", "", "Les premières fiches de plugins arrivent bientôt."),
        textElement("p", "", "Vous pouvez déjà nous présenter le plugin personnalisé dont votre serveur a besoin."),
      );
      const link = textElement("a", "button button-secondary", "Demander un plugin personnalisé");
      link.href = pluginContactLink(email, "nouveau plugin");
      empty.appendChild(link);
      grid.appendChild(empty);
      return;
    }

    plugins.forEach((plugin) => {
      const article = document.createElement("article");
      article.className = "plugin-card";

      const imagePath = safeImagePath(plugin.image);
      if (imagePath) {
        const media = document.createElement("div");
        media.className = "plugin-media";
        const image = document.createElement("img");
        image.src = imagePath;
        image.alt = plugin.alt || `Interface du plugin ${plugin.title || "Minecraft"}`;
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
        textElement("p", "example-kicker", plugin.label || "Plugin réalisé"),
        textElement("span", "plugin-status", plugin.status || "Sur mesure"),
      );
      body.append(
        topLine,
        textElement("h3", "", plugin.title || "Plugin Minecraft"),
        textElement("p", "plugin-summary", plugin.summary || ""),
      );

      if (Array.isArray(plugin.features) && plugin.features.length) {
        const features = document.createElement("ul");
        features.className = "plugin-features";
        plugin.features.slice(0, 6).forEach((feature) => features.appendChild(textElement("li", "", String(feature))));
        body.appendChild(features);
      }

      const footer = document.createElement("div");
      footer.className = "plugin-footer";
      footer.appendChild(textElement("span", "plugin-version", plugin.versions || "Version sur demande"));
      const contact = textElement("a", "plugin-contact", "Demander un plugin similaire");
      contact.href = pluginContactLink(email, plugin.title || "plugin Minecraft");
      footer.appendChild(contact);
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
  document.querySelectorAll("[data-contact-email]").forEach((element) => {
    element.textContent = email;
    if (element instanceof HTMLAnchorElement) element.href = `mailto:${email}`;
  });
  renderPlugins(Array.isArray(content.plugins) ? content.plugins : [], email);
  renderReviews(Array.isArray(content.reviews) ? content.reviews : []);
});
