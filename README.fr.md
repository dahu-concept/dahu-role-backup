# Dahu Role Backup

Un filet de sécurité pour les rôles et les capacités de WordPress.

Développé et maintenu par [Hugo Vial-Jaime — Dahu-Concept](https://dahu-concept.fr).

*La version anglaise de ce document est dans `README.md`. En cas d'écart, c'est
elle qui fait foi : c'est elle qui part sur wordpress.org.*

## Le problème

WordPress garde tous les rôles et toutes les capacités dans une seule option.
Une extension qui y écrit sans précaution, une migration, une mise à jour au
mauvais moment, et un rôle dont la moitié du site dépend a disparu — sans rien
pour le remettre, parce que personne n'a jamais pensé à le copier.

Cette extension le copie. Chaque jour, et de nouveau dès que l'option change.

## Comment ça marche

- Un instantané est pris **chaque jour**, et de nouveau **dès que l'option des
  rôles est écrite**, pour qu'un changement soit saisi au moment où il arrive.
- Rien n'est écrit si les rôles sont identiques au dernier instantané.
- Un instantané qui suit une **disparition** est marqué et affiché en premier.
- La restauration est **additive par défaut** : seuls les rôles manquants sont
  remis. Une restauration intégrale est une action distincte et explicite.
- Un instantané venant d'une autre installation est **refusé**, sur le préfixe
  de table et le nom de l'option.

### Une rétention qui ne se retourne pas contre elle-même

Les instantanés sont plafonnés — trente quotidiens, trente sur changement —
mais le meilleur instantané récent est conservé **en plus** de ce quota. Sans
cette exception, une série de disparitions répétées ferait tourner la rétention
jusqu'à ce qu'aucune copie ne contienne plus les rôles perdus : le filet de
sécurité se viderait tout seul exactement au moment où il sert.

### Le rôle qui disparaît sans que rien n'ait échoué

La façon habituelle dont des rôles disparaissent n'est pas un bogue visible.
WordPress garde tous les rôles dans une seule option, et ajouter une seule
capacité réécrit la totalité de cette option à partir de la copie que la requête
en cours a chargée à son démarrage. Une requête commencée avant qu'une autre ne
crée un rôle va donc, dès qu'elle ajoute une capacité quelconque, réécrire sa
propre copie plus ancienne par-dessus — et le rôle nouvellement créé n'existe
plus. Rien n'a échoué. Rien n'a été journalisé.

L'extension surveille précisément ce cas, en lisant la ligne stockée en base
directement au lieu de se fier à la copie que porte la requête, ce qui est la
seule façon de le voir. Quand une écriture est sur le point de faire disparaître
des rôles encore présents en base, un instantané est pris **depuis la base**
d'abord, marqué comme sauvetage, en nommant les rôles qui allaient partir.

## Stockage

Sous `wp-content/dahu-role-backups/`, et non dans les téléversements, qui sont
servis par le serveur web et ramassés par les outils de synchronisation. Un
`.htaccess` et un `index.php` y sont posés. Sur un hébergement où
`wp-content/` est en lecture seule, l'extension se replie sur le dossier des
téléversements au lieu d'échouer en silence.

```php
add_filter( 'dahu_role_backup_directory', function () {
    return '/un/chemin/hors/de/la/racine/web';
} );
```

## WP-CLI

```
wp dahu-role-backup list
wp dahu-role-backup snapshot
wp dahu-role-backup diff <fichier>
wp dahu-role-backup restore <fichier> [--full]
```

## Prérequis

| | |
|---|---|
| WordPress | 6.0 ou plus |
| PHP | 7.4 ou plus |

## Désinstallation

Les instantanés sont **conservés**. Ils sont la raison d'être de l'extension, et
une désinstallation est souvent une étape d'une réinstallation : une routine qui
les effacerait serait destructrice précisément quand le site a un problème de
rôles à régler. Le répertoire est laissé pour que vous le retiriez en
connaissance de cause.

## Services externes

Aucun. Les instantanés restent sur votre propre serveur.

## Licence

MIT — voir [LICENSE](LICENSE).
