# Tests de l'API

La suite PHPUnit couvre les entités, la logique de réservation, la documentation OpenAPI (toutes les routes API), ainsi que les parcours HTTP : inscription/connexion, rôles, compte, réservation/capacité, carte et galerie.

Les tests HTTP qui écrivent en base ne démarrent que si `RUN_DB_TESTS=1`. Ils refusent toute base qui n'est pas SQLite en mémoire ou dont le nom ne se termine pas par `_test`, puis recréent le schéma **de cette base uniquement** avant chaque scénario. Ne jamais utiliser une URL de production.

Sous Windows avec l'extension SQLite disponible :

```powershell
$env:DATABASE_URL='sqlite:///:memory:'
$env:RUN_DB_TESTS='1'
php -d extension=pdo_sqlite vendor/bin/phpunit
```

Sans ces variables, les tests sans base restent exécutables avec `php vendor/bin/phpunit` ; les scénarios HTTP à base de données sont ignorés explicitement.

La CI utilise PostgreSQL 18 avec une base jetable `quai_antique_test`. Elle exécute d'abord les deux migrations de `migrations_prod` via `config/migrations_test.yaml`, puis toute la suite. Elle ne possède aucun identifiant de production. La vérification PostgreSQL de la migration ne peut pas être reproduite localement sans un compte PostgreSQL dédié ; SQLite valide les parcours applicatifs, pas le SQL PostgreSQL.
