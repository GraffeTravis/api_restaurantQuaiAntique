# Quai Antique - API

API REST Symfony du projet Quai Antique, realisee pour l'ECF de la formation Developpeur Web et Web Mobile.

Elle fournit les donnees et les actions necessaires au front-end : authentification, compte client, carte, galerie, restaurant et reservations.

## Stack

- PHP 8.4 ou plus
- Symfony 8.1
- Doctrine ORM
- PostgreSQL 16+ (Docker et CI utilisent PostgreSQL 18). Heroku prend actuellement en charge les versions 16, 17 et 18; verifier la version effective du service avant un deploiement ou une migration.
- References Heroku: [versions PostgreSQL prises en charge](https://devcenter.heroku.com/articles/heroku-postgres-version-support) et [offres PostgreSQL](https://devcenter.heroku.com/articles/heroku-postgres-plans).
- PHPUnit
- Nelmio API Doc

## Installation locale

```bash
composer install
docker compose up -d database
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load --no-interaction
php -S 127.0.0.1:8000 -t public
```

`doctrine:fixtures:load` vide et recharge les tables par defaut. Utilisez-le uniquement sur une base locale jetable ou une base de test; ne lancez jamais cette commande sur la production.

La documentation OpenAPI est disponible sur :

```text
http://127.0.0.1:8000/api/doc
```

## Configuration

La configuration versionnee utilise PostgreSQL :

```text
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"
```

Pour un environnement local different, utiliser un fichier `.env.local`, qui ne doit pas etre versionne.

Pour le front-end local, l'origine CORS autorisee par defaut couvre `localhost` et `127.0.0.1`.

## Comptes de demonstration

Les fixtures creent :

- des comptes clients `email.1@studi.fr` a `email.20@studi.fr`, avec les mots de passe `password1` a `password20`
- un compte administrateur `admin@quai-antique.local`, mot de passe `AdminPassword123!`

Ces comptes servent uniquement aux tests et demonstrations locales.

## Routes principales

- `POST /api/registration` : inscription client
- `POST /api/login` : connexion
- `POST /api/logout` : revoquer la session
- `GET /api/account/me` : consulter son compte
- `PUT /api/account/me` : modifier son compte
- `PUT /api/account/password` : modifier son mot de passe
- `GET /api/restaurants/{id}` : consulter le restaurant
- `GET /api/categories` : consulter les categories
- `GET /api/foods` : consulter les plats
- `GET /api/menus` : consulter les menus
- `GET /api/restaurants/{restaurantId}/pictures` : consulter la galerie du restaurant
- `GET /api/restaurants/{restaurantId}/pictures/{pictureId}` : consulter une image
- `GET /api/bookings/availability` : verifier un creneau
- `POST /api/bookings` : creer une reservation
- `GET /api/bookings/` : consulter ses reservations
- `PUT /api/bookings/{id}` : modifier une reservation
- `DELETE /api/bookings/{id}` : supprimer une reservation
- `GET /api/bookings/admin` : consulter les reservations en administrateur

Les routes de modification des contenus sont protegees par le role administrateur.

## Tests

```bash
php bin/phpunit
```

Sans PostgreSQL, les tests des flux necessitant une base sont ignores. Pour les activer, utilisez une base jetable nommee `*_test`, appliquez `config/migrations_test.yaml`, puis definissez `RUN_DB_TESTS=1`. Le garde-fou PHPUnit refuse de reinitialiser une base dont le nom n'est pas suffixe par `_test`.

Les tests couvrent actuellement :

- les entites principales
- la disponibilite des creneaux de reservation
- les droits de gestion d'une reservation
- l'acces a la documentation API
- le refus d'une connexion sans identifiants

## Deploiement

Avant mise en production :

- definir une vraie valeur `APP_SECRET`
- configurer `DATABASE_URL` avec la base de production
- configurer `CORS_ALLOW_ORIGIN` pour les origines exactes du front de production et de preview, sans joker large
- configurer le CORS avec credentials pour les cookies; le frontend HTTPS utilise `HttpOnly`, `Secure` et `SameSite=Lax` en production, et les ecritures verifient l'en-tete `Origin`
- executer les migrations
- ne jamais versionner de secrets dans `.env.local`
- conserver les sauvegardes avant toute migration et verifier le statut de la migration

### Deploiement Heroku

Le projet contient un `Procfile` pour Heroku :

```text
web: heroku-php-apache2 public/
release: php bin/console doctrine:migrations:migrate --no-interaction
```

Le processus `web` lance l'API Symfony depuis le dossier `public/`. Le processus `release` execute les migrations Doctrine a chaque deploiement.

Les anciennes migrations du dossier `migrations/` proviennent de l'historique local du projet. En production Heroku, le projet utilise le dossier `migrations_prod/`, qui contient une migration PostgreSQL compatible avec Heroku Postgres.

Variables a configurer dans Heroku (adapter les origines a vos domaines exacts) :

```text
APP_ENV=prod
APP_SECRET=<secret long genere>
CORS_ALLOW_ORIGIN=^https://(quaiantique\.tech|www\.quaiantique\.tech|quai-antique-site-web\.vercel\.app)$
```

La variable `DATABASE_URL` est ajoutee automatiquement par l'add-on Heroku Postgres.

Commandes utiles :

```bash
heroku create <nom-application>
heroku addons:create heroku-postgresql:essential-0 -a <nom-application>
heroku config:set APP_ENV=prod APP_SECRET=<secret> CORS_ALLOW_ORIGIN='^https://(quaiantique\.tech|www\.quaiantique\.tech|quai-antique-site-web\.vercel\.app)$' -a <nom-application>
git push heroku main
heroku run --app <nom-application> php bin/console app:seed-production --admin-email=<email-admin>
```

Pour creer le premier administrateur, fournissez `INITIAL_ADMIN_PASSWORD` temporairement via le tableau de bord des variables secretes avant d'executer le seed; retirez cette variable apres usage et changez le mot de passe a la premiere connexion. `app:seed-production` est l'initialisation minimale et repetable. N'utilisez pas `doctrine:fixtures:load` sur une base de production: les fixtures sont des donnees de demonstration et cette commande peut supprimer les donnees existantes.

Les images de galerie sont actuellement encodees en Base64 dans PostgreSQL et servies par les routes `/api/restaurants/{restaurantId}/pictures`. Il ne s'agit pas de fichiers a placer dans `public/uploads`; une migration vers un stockage objet reste une evolution distincte.

Apres deploiement, verifier :

```text
https://<nom-application>.herokuapp.com/api/doc
https://<nom-application>.herokuapp.com/api/restaurants/1
```
