# Quai Antique — API REST

API REST du projet **Quai Antique**, une application web vitrine et de réservation pour un restaurant gastronomique situé à Chambéry.

Le projet est réalisé dans le cadre de la formation **Développeur Web et Web Mobile** et de l'ECF. L'application doit permettre aux visiteurs de consulter le restaurant, sa carte, ses menus et sa galerie, tandis que les clients peuvent gérer leurs réservations et que l'administrateur peut administrer le contenu et les réservations.

## Présentation

L'API fournit le back-end nécessaire au fonctionnement de l'application :

* authentification des utilisateurs ;
* gestion des comptes clients ;
* gestion du restaurant ;
* gestion de la galerie d'images ;
* gestion des catégories et des plats ;
* gestion des menus ;
* création et gestion des réservations ;
* contrôle des droits selon le rôle de l'utilisateur ;
* exposition de ressources REST consommées par le front-end.

Le projet respecte une séparation entre les responsabilités **front-end**, **API back-end** et **base de données**.

## Architecture du projet

```text
Quai Antique
│
├── Front-end
│   └── Interface web du restaurant
│
└── Back-end
    ├── API REST Symfony
    ├── Doctrine ORM
    ├── PostgreSQL
    ├── Symfony Security
    ├── Symfony Validator
    └── Documentation API
```

Repositories associés :

* **Front-end** : https://github.com/GraffeTravis/QuaiAntiqueSiteWeb
* **Back-end / API** : https://github.com/GraffeTravis/api_restaurantQuaiAntique

## Technologies

| Technologie       | Utilisation                            |
| ----------------- | -------------------------------------- |
| PHP 8.4+          | Langage du back-end                    |
| Symfony 8.1       | Framework PHP                          |
| Doctrine ORM 3.6  | Mapping objet-relationnel et accès BDD |
| PostgreSQL        | Base de données relationnelle          |
| Symfony Security  | Authentification et autorisation       |
| Symfony Validator | Validation des données                 |
| Nelmio API Doc    | Documentation de l'API                 |
| Nelmio CORS       | Gestion des requêtes cross-origin      |
| PHPUnit           | Tests automatisés                      |
| Composer          | Gestion des dépendances PHP            |

## Structure

La structure principale du projet Symfony suit l'organisation conventionnelle du framework :

```text
.
├── config/
├── migrations/
├── public/
├── src/
│   ├── Controller/
│   ├── Entity/
│   ├── Repository/
│   └── Security/
├── tests/
├── templates/
├── var/
├── vendor/
├── .env
├── composer.json
└── symfony.lock
```

Les entités Doctrine représentent notamment les données métier du restaurant, des utilisateurs et des réservations.

## Rôles utilisateurs

L'application distingue principalement trois situations.

### Visiteur

Un visiteur peut :

* consulter les informations du restaurant ;
* consulter la carte ;
* consulter les menus ;
* consulter la galerie ;
* créer un compte ;
* se connecter.

### Client

Un client authentifié peut :

* effectuer une réservation ;
* consulter ses réservations ;
* modifier une réservation ;
* supprimer une réservation ;
* modifier ses données personnelles ;
* supprimer son compte.

### Administrateur

L'administrateur peut :

* gérer les informations du restaurant ;
* configurer les horaires des services ;
* configurer la capacité maximale ;
* gérer la galerie ;
* gérer les catégories ;
* gérer les plats ;
* gérer les menus ;
* consulter, modifier et supprimer les réservations.

## Sécurité

Plusieurs mécanismes de sécurité sont intégrés au back-end :

* authentification des utilisateurs ;
* hachage des mots de passe via le système de sécurité Symfony ;
* authentification des requêtes API ;
* contrôle des rôles et permissions ;
* validation des données côté serveur ;
* configuration CORS ;
* séparation des accès administrateur et client ;
* protection des variables sensibles via la configuration d'environnement.


## Base de données

Le projet utilise **PostgreSQL** avec **Doctrine ORM**.

Les évolutions du schéma sont gérées avec les migrations Doctrine.

Pour vérifier le mapping :

```bash
php bin/console doctrine:schema:validate
```

Pour créer une migration après une modification des entités :

```bash
php bin/console make:migration
```

Puis :

```bash
php bin/console doctrine:migrations:migrate
```

## Installation

### Prérequis

* PHP 8.4 ou supérieur ;
* Composer ;
* PostgreSQL ;
* Symfony CLI recommandé ;
* Git.

### 1. Cloner le repository

```bash
git clone https://github.com/GraffeTravis/api_restaurantQuaiAntique.git
cd api_restaurantQuaiAntique
```

### 2. Installer les dépendances

```bash
composer install
```

### 3. Configurer l'environnement

Créer un fichier local à partir de la configuration d'environnement du projet et renseigner notamment :

```dotenv
APP_ENV=dev
APP_SECRET=your_local_secret
DATABASE_URL="postgresql://user:password@127.0.0.1:5432/restaurant_quai_antique"
```

Les valeurs réelles de production doivent être configurées directement dans l'environnement du serveur d'hébergement.

### 4. Préparer la base de données

Créer la base puis appliquer les migrations :

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

### 5. Charger les fixtures

Si les fixtures sont utilisées dans l'environnement de développement :

```bash
php bin/console doctrine:fixtures:load
```

> Cette commande réinitialise les données de la base selon la configuration des fixtures. À utiliser uniquement dans un environnement approprié.

### 6. Lancer l'application

Avec Symfony CLI :

```bash
symfony server:start
```

L'API est alors accessible localement sur l'adresse fournie par Symfony CLI.

## Documentation de l'API

La documentation de l'API est générée avec **Nelmio API Doc**.

En environnement de développement, la documentation JSON de l'API est disponible via :

```text
/api/doc.json
```

Elle permet notamment d'examiner les routes et les contrats exposés par l'API.

## Authentification

Les endpoints protégés nécessitent une authentification.

Le client doit fournir les informations d'authentification attendues par l'API dans ses requêtes.

Exemple de principe :

```http
X-AUTH-TOKEN: <token>
```

Les permissions sont ensuite contrôlées côté serveur selon le rôle de l'utilisateur.

> Le contrôle effectué par le front-end ne constitue pas une mesure de sécurité. La décision d'autorisation doit toujours être prise par l'API.

## Réservations

La réservation constitue une partie centrale du projet.

Le système doit prendre en compte :

* le nombre de convives ;
* la date ;
* l'heure ;
* les allergies ;
* les horaires du service ;
* des créneaux de 15 minutes ;
* la capacité maximale du restaurant ;
* l'état d'authentification de l'utilisateur.

La disponibilité doit être vérifiée côté serveur au moment de la création de la réservation afin d'éviter les dépassements de capacité.

## Tests

Les tests automatisés sont exécutés avec PHPUnit.

Lancer la suite de tests :

```bash
php bin/phpunit
```

Avant une livraison, il est recommandé de vérifier au minimum :

* inscription ;
* authentification ;
* contrôle des rôles ;
* accès aux endpoints administrateur ;
* création d'une réservation ;
* validation de la capacité ;
* validation des créneaux ;
* modification d'une réservation ;
* suppression d'une réservation ;
* gestion des ressources administratives.

## Vérifications utiles

Vérifier le mapping Doctrine :

```bash
php bin/console doctrine:schema:validate
```

Vérifier l'état des migrations :

```bash
php bin/console doctrine:migrations:status
```

Vider le cache :

```bash
php bin/console cache:clear
```

## Déploiement

Le déploiement final doit séparer clairement :

```text
Front-end
    ↓
API REST
    ↓
PostgreSQL
```

En production :

* `APP_ENV` doit être configuré sur `prod` ;
* les secrets doivent être fournis par l'environnement d'hébergement ;
* la base PostgreSQL de production doit être distincte de la base de développement ;
* CORS doit être limité aux domaines réellement utilisés ;
* l'API doit être accessible en HTTPS ;
* les identifiants administrateur ne doivent pas être présents dans le code front-end.

## Périmètre fonctionnel

Le projet répond au cahier des charges du **Quai Antique**, notamment sur les fonctionnalités suivantes :

* [x] authentification ;
* [x] inscription client ;
* [x] gestion des utilisateurs ;
* [x] gestion du restaurant ;
* [x] galerie d'images ;
* [x] catégories ;
* [x] plats ;
* [x] menus ;
* [x] réservations ;
* [x] gestion des réservations ;
* [x] espace client ;
* [x] espace administrateur ;
* [x] documentation API.

> La checklist représente le périmètre fonctionnel visé par le projet. Pour connaître précisément l'état d'implémentation de chaque fonctionnalité, consulter les issues, commits et tests du repository.

## Contexte ECF

Ce repository fait partie d'un projet réalisé dans le cadre du titre professionnel **Développeur Web et Web Mobile**.

La documentation du projet doit notamment permettre de présenter :

* l'analyse des besoins ;
* les choix techniques ;
* l'environnement de travail ;
* les mécanismes de sécurité ;
* la veille technologique ;
* les recherches effectuées pendant le développement ;
* les liens vers les repositories ;
* les informations nécessaires au déploiement et à la démonstration.

## Auteur

**Travis Graffe**

Projet réalisé dans le cadre de la formation Développeur Web et Web Mobile.

---

## Licence

Projet pédagogique réalisé dans le cadre de la formation.

Les contenus fournis par le cahier des charges sont utilisés uniquement dans le contexte du projet ECF.
