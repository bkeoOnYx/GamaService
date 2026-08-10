const textElement = (tag, className, value) => {
  const element = document.createElement(tag);
  if (className) element.className = className;
  element.textContent = value;
  return element;
};

const safeImagePath = (value) => {
  if (typeof value !== "string") return "assets/logo-gamaservice.webp";
  if (/^(?:assets\/[a-zA-Z0-9/_-]+\.(?:jpg|jpeg|png|webp)|media\.php\?file=[a-f0-9]{32}\.(?:jpg|png|webp))$/.test(value)) return value;
  return "assets/logo-gamaservice.webp";
};

const loadContent = async () => {
  for (const url of ["api/content.php", "data/default-content.json"]) {
    try {
      const response = await fetch(url, { headers: { Accept: "application/json" } });
      if (!response.ok) continue;
      return await response.json();
    } catch {
      // Try the static seed when PHP is unavailable in a local preview.
    }
  }
  return null;
};

const renderExamples = (examples) => {
  document.querySelectorAll("[data-example-grid]").forEach((grid) => {
    grid.replaceChildren();
    examples.forEach((example) => {
      const article = document.createElement("article");
      article.className = "example-card";

      const image = document.createElement("img");
      image.src = safeImagePath(example.image);
      image.alt = example.alt || "Concept de serveur Minecraft par GamaService";
      image.width = 1600;
      image.height = 1000;
      image.loading = "lazy";
      article.appendChild(image);

      const body = document.createElement("div");
      body.className = "example-body";
      body.append(
        textElement("p", "example-kicker", example.kicker || "Concept de démonstration"),
        textElement("h3", "", example.title || "Projet Minecraft"),
        textElement("p", "", example.description || ""),
      );

      if (Array.isArray(example.tags) && example.tags.length) {
        const tags = document.createElement("ul");
        tags.className = "tag-list";
        example.tags.slice(0, 6).forEach((tag) => tags.appendChild(textElement("li", "", String(tag))));
        body.appendChild(tags);
      }

      article.appendChild(body);
      grid.appendChild(article);
    });
  });
};

const renderReviews = (reviews) => {
  document.querySelectorAll("[data-review-grid]").forEach((grid) => {
    grid.replaceChildren();
    if (!reviews.length) {
      const empty = textElement(
        "p",
        "empty-state",
        "Les premiers avis vérifiés seront publiés ici après livraison des projets.",
      );
      grid.appendChild(empty);
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
  renderExamples(Array.isArray(content.examples) ? content.examples : []);
  renderReviews(Array.isArray(content.reviews) ? content.reviews : []);
});
