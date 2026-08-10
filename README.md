# GamaService

Site vitrine de [gamaservice.fr](https://gamaservice.fr/), déployé sur un hébergement web OVH depuis la branche `main`.

## Pages publiques

- Minecraft, Garry's Mod, sites web et graphisme
- Galerie de concepts Minecraft administrable
- Avis clients administrables
- Formulaire de brief ouvrant la messagerie du visiteur

## Administration

L'espace `/admin/` utilise un lien de connexion à usage unique envoyé à `support.gamaservice@gmail.com`. Aucun mot de passe n'est stocké dans le dépôt.

Les contenus modifiés et les images téléversées sont conservés dans `gamaservice-data`, à côté de la racine web. Ils ne sont donc pas remplacés par les déploiements Git.

Prérequis OVH : PHP 8.1 ou supérieur, extension Fileinfo et fonction `mail()` active.

## Développement local

Les pages publiques restent consultables avec un serveur statique. Sans PHP, `content.js` utilise automatiquement `data/default-content.json` comme contenu de démonstration.
