# Statistiques des réservations

`GET /api/admin/statistics/bookings` exige `ROLE_ADMIN`. PostgreSQL reste la source de référence ; MongoDB fournit une projection asynchrone, sans coordonnées, identifiant client ou allergies. L'administration du frontend comporte un onglet **Statistiques**.

## Règles et contrat

Paramètres facultatifs : `from` et `to` au format `YYYY-MM-DD` (bornes incluses, premier jour du mois et aujourd'hui par défaut), `restaurantId` (1 par défaut), `granularity=day|week|month`. Une période peut couvrir au maximum 366 jours. Les dates sont celles du **repas**, interprétées en `Europe/Paris`, comme les réservations existantes. Les semaines commencent le lundi ; la clé d'une semaine ou d'un mois désigne son premier jour même si le filtre commence en cours de période. Seules les dates dans les bornes demandées sont comptées. Les périodes sans réservation sont complétées avec des zéros.

La réponse comprend :

- `filters` : filtres normalisés et fuseau ;
- `totals` : `bookings`, `guests`, `averageGuests` (0 si aucune réservation) ;
- `series` : `date`, `bookings`, `guests` par période ;
- `services` : `service=lunch|dinner|unknown`, réservations et couverts ;
- `weekdays` : `day` ISO de 1 (lundi) à 7 (dimanche), réservations et couverts ;
- `slots` : `hour`, réservations et couverts, triés par réservations décroissantes puis heure croissante ;
- `synchronization` : `lastSyncedAt` (dernier document synchronisé du restaurant, toutes périodes confondues), `pending`, `failed` (nombres de messages, pas de réservations).

Exemple : `/api/admin/statistics/bookings?from=2026-10-01&to=2026-10-31&restaurantId=1&granularity=week`.

Les réservations supprimées sont exclues, y compris des périodes passées. Il ne s'agit donc ni d'un historique d'annulations, ni de présences constatées, ni de chiffre d'affaires. Le service est enregistré selon les horaires du restaurant au moment de la mutation/import. Il reste stable si l'heure et le restaurant ne changent pas. Les anciens créneaux hors horaires actuels sont classés « Non déterminé », sans inventer leur service historique. `createdAt` et `updatedAt` conservent les horodatages SQL existants ; ils ne servent pas au regroupement des repas.

## Fiabilité et stockage

La migration installe un trigger PostgreSQL `AFTER INSERT OR UPDATE OR DELETE` sur `booking`. Dans **la transaction de la réservation**, il enregistre un instantané dans `booking_analytics_state` et un message JSON Symfony dans `messenger_messages`, file `analytics`. Cela couvre aussi les écritures ORM hors contrôleur et les suppressions en cascade. Un rollback retire réservation, instantané et message ensemble. Le navigateur ne contacte jamais MongoDB.

Le worker `messenger:consume analytics` écrit dans `booking_analytics`. Chaque document a `_id=bookingId` et une version issue d'une séquence PostgreSQL. La comparaison et le remplacement sont atomiques dans MongoDB : un message ancien ne remplace jamais une version récente. Rejouer la même version ne double pas les comptes. Les suppressions conservent un marqueur versionné dans les deux bases pour empêcher toute résurrection par un ancien message. Ne purgez pas ces marqueurs ni la séquence indépendamment des files et sauvegardes.

Deux index sont créés par le worker lors de sa première synchronisation et par la commande de réconciliation : `(restaurantId, deleted, date)` pour les agrégations et `(restaurantId, syncedAt)` pour la dernière synchronisation. Les requêtes MongoDB ont un temps maximal ; une indisponibilité renvoie HTTP 503 sans bloquer la prise de réservations. Les chiffres sont à cohérence éventuelle : `lastSyncedAt` n'est pas une garantie d'exhaustivité ; consulter également les files et les erreurs. Les messages en échec restent consultables dans `analytics_failed` après dix tentatives espacées, jusqu'à cinq minutes entre tentatives.

## Installation et exploitation

Installer l'extension PHP `mongodb` 2.5+ et les dépendances verrouillées via `composer install`. Docker installe l'extension dans l'image. Configurer :

```dotenv
MONGODB_URI=mongodb://utilisateur:mot-de-passe@hote:27017/?authSource=admin
MONGODB_DATABASE=quai_antique_analytics
```

En production, utiliser un compte MongoDB limité à la base analytique et TLS selon l'hébergement. Les valeurs locales par défaut ciblent `127.0.0.1:27017`. Les secrets restent dans les variables d'environnement ou `.env.local`.

Avec Docker, compléter `.env.docker` d'après `.env.docker.example` (notamment `MONGO_USER` et `MONGO_PASSWORD`, à encoder pour une URI s'ils contiennent des caractères réservés), puis :

```bash
docker compose --env-file .env.docker up -d --build database mongodb api
docker compose --env-file .env.docker exec api php bin/console doctrine:migrations:migrate --no-interaction
docker compose --env-file .env.docker exec api php bin/console app:analytics:reconcile
docker compose --env-file .env.docker up -d --build analytics-worker
```

La migration importe automatiquement les réservations existantes dans la file ; la réconciliation peut aussi être utilisée pour remettre en file l'état actuel après perte ou restauration de MongoDB. Elle rejoue les marqueurs de suppression, conserve les versions monotones et bloque temporairement les écritures de réservation pendant la lecture SQL cohérente. La lancer en période calme pour une base volumineuse. Elle ne supprime ni réservation ni message en échec et ne reconstruit pas les suppressions antérieures à l'installation du module. Une restauration SQL doit être accompagnée d'une reconstruction cohérente de la projection MongoDB ; ne mélangez pas des versions issues de sauvegardes différentes.

Hors Docker :

```bash
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:analytics:reconcile
php bin/console messenger:consume analytics --time-limit=3600 --memory-limit=128M
php bin/console messenger:stats analytics analytics_failed
php bin/console messenger:failed:show --transport=analytics_failed
php bin/console messenger:failed:retry --transport=analytics_failed --force
```

Le `Procfile` déclare le worker Heroku. Configurer MongoDB et activer le processus `worker` sur l'environnement choisi ; le push Git seul ne provisionne pas MongoDB et n'active pas ce processus. Aucun déploiement ou provisionnement de production n'est inclus dans cette fonctionnalité.

## Validation

La CI démarre PostgreSQL 18 et MongoDB 8. Les tests existants restent activables avec `RUN_DB_TESTS=1`. Les nouveaux tests d'intégration nécessitent aussi `RUN_ANALYTICS_TESTS=1`, `MONGODB_URI` et `MONGODB_DATABASE` avec un nom finissant par `_test`. Ils créent un schéma PostgreSQL isolé par test et réinitialisent la collection de la base MongoDB de test uniquement.

```bash
APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction --configuration=config/migrations_test.yaml
RUN_DB_TESTS=1 RUN_ANALYTICS_TESTS=1 MONGODB_DATABASE=quai_analytics_test php vendor/bin/phpunit
```

Sont couverts : transaction/rollback, absence de données personnelles, décodage et consommation Messenger, totaux et regroupements au changement d'heure, déplacement d'une réservation, rejeu et messages désordonnés, suppression, reconstruction MongoDB, panne/reprise, permissions, validation des filtres et isolation par restaurant. Le frontend vérifie les graphiques locaux, les filtres, les états de chargement/vide/retard/erreur, le retour après navigation et l'affichage mobile.

Références : [transport Doctrine Messenger](https://symfony.com/doc/current/messenger.html#doctrine-transport), [agrégations MongoDB PHP](https://www.mongodb.com/docs/php-library/current/reference/method/mongodbcollection-aggregate/), [intégration Chart.js](https://www.chartjs.org/docs/latest/getting-started/integration).
