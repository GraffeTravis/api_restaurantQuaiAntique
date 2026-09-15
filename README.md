# Quai Antique - API

API REST Symfony du projet Quai Antique, realisee pour l'ECF de la formation Developpeur Web et Web Mobile.

Elle fournit les donnees et les actions necessaires au front-end : authentification, compte client, carte, galerie, restaurant et reservations.

## Stack

- PHP 8.4 ou plus
- Symfony 8.1
- Doctrine ORM
- PostgreSQL 16
- PHPUnit
- Nelmio API Doc

## Installation locale

```bash
composer install
docker compose up -d database
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load
php -S 127.0.0.1:8000 -t public
```

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
- `GET /api/account/me` : consulter son compte
- `PUT /api/account/me` : modifier son compte
- `PUT /api/account/password` : modifier son mot de passe
- `GET /api/restaurants/{id}` : consulter le restaurant
- `GET /api/categories` : consulter les categories
- `GET /api/foods` : consulter les plats
- `GET /api/menus` : consulter les menus
- `GET /api/pictures` : consulter la galerie
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
- configurer `CORS_ALLOW_ORIGIN` avec l'URL publique du front-end
- executer les migrations
- ne jamais versionner de secrets dans `.env.local`

### Deploiement Heroku

Le projet contient un `Procfile` pour Heroku :

```text
web: heroku-php-apache2 public/
release: php bin/console doctrine:migrations:migrate --no-interaction
```

Le processus `web` lance l'API Symfony depuis le dossier `public/`. Le processus `release` execute les migrations Doctrine a chaque deploiement.

Variables a configurer dans Heroku :

```text
APP_ENV=prod
APP_SECRET=<secret long genere>
CORS_ALLOW_ORIGIN=^https://<url-front-vercel>\.vercel\.app$
```

La variable `DATABASE_URL` est ajoutee automatiquement par l'add-on Heroku Postgres.

Commandes utiles :

```bash
heroku create <nom-application>
heroku addons:create heroku-postgresql:mini -a <nom-application>
heroku config:set APP_ENV=prod APP_SECRET=<secret> CORS_ALLOW_ORIGIN='^https://<url-front-vercel>\.vercel\.app$' -a <nom-application>
git push heroku main
heroku run php bin/console doctrine:fixtures:load --no-interaction -a <nom-application>
```

Apres deploiement, verifier :

```text
https://<nom-application>.herokuapp.com/api/doc
https://<nom-application>.herokuapp.com/api/restaurants/1
```
