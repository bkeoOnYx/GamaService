# GamaService

Vitrine statique de GamaService organisée autour de trois pôles : jeux vidéo (Minecraft et Garry's Mod), sites internet et graphisme.

## Aperçu local

Le site n'a aucune dépendance. Ouvrez simplement `index.html` dans un navigateur, ou servez le dossier avec un petit serveur HTTP local.

## Publication OVH

Ce dépôt est prêt pour un hébergement web mutualisé OVH : aucun build, aucune base de données et aucune dépendance serveur ne sont nécessaires.

1. Dans l'espace client OVHcloud, ouvrir **Web Cloud → Hébergements → gamaservice.fr → Multisite**.
2. Vérifier le **Dossier racine** associé à `gamaservice.fr` et `www.gamaservice.fr` (souvent `www`).
3. Dans **FTP - SSH**, récupérer l'hôte, l'utilisateur et le mot de passe FTP/SFTP.
4. Envoyer à la racine du domaine les fichiers `index.html`, `styles.css`, `script.js`, `.htaccess`, `.nojekyll`, `robots.txt`, `sitemap.xml` et le dossier `assets/`.
5. Supprimer l'éventuel `index.html` par défaut créé par OVH avant l'envoi.
6. Vérifier que le certificat SSL OVH est actif, puis tester `https://gamaservice.fr`.

Pour créer une archive locale prête à envoyer :

```powershell
New-Item -ItemType Directory -Force dist
Compress-Archive -Force -LiteralPath index.html,styles.css,script.js,.htaccess,.nojekyll,robots.txt,sitemap.xml,assets -DestinationPath dist/gamaservice-ovh.zip
```

Le formulaire de brief fonctionne entièrement dans le navigateur et ne transmet aucune donnée. Un lien Discord ou une adresse e-mail pourra être ajouté lorsque le canal de contact sera prêt.
