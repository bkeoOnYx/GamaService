# GamaService

Site vitrine de [gamaservice.fr](https://gamaservice.fr/), déployé sur un hébergement web OVH depuis la branche `main`.

## Pages publiques

- Minecraft, Garry's Mod, sites web et graphisme
- Portfolios administrables pour Minecraft, Garry's Mod, sites web et graphisme
- Sélection générale limitée à trois réalisations sur l'accueil
- Avis clients administrables
- Contact par e-mail ou Discord et formulaire de brief avec moyen de réponse facultatif
- Mentions légales et politique de confidentialité accessibles depuis chaque pied de page

## Administration

L'espace `/admin/` utilise le compte `admin` et un mot de passe haché conservé hors de la racine web. Le premier mot de passe, ainsi que sa réinitialisation, passent par un lien valable 15 minutes envoyé à `support.gamaservice@gmail.com`. Aucun secret n'est stocké dans le dépôt.

Les contenus modifiés, les identifiants sécurisés et les captures téléversées sont conservés dans `gamaservice-data`, à côté de la racine web. Ils ne sont donc ni accessibles directement, ni remplacés par les déploiements Git.

Prérequis OVH : PHP 8.1 ou supérieur, extension Fileinfo et fonction `mail()` active.

## Développement local

Les pages publiques restent consultables avec un serveur statique. Sans PHP, `content.js` utilise automatiquement `data/default-content.json` comme contenu de repli.
