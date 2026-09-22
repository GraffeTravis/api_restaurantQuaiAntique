# Remédiations P1

Branche de travail : `fix/audit-p1`.

## Corrections incluses

- Les titres, attributs et URLs de photos sont validés et échappés côté front.
- Les jetons API sont stockés sous forme de hash, expirent, sont révoqués à la déconnexion et après changement de mot de passe.
- Les inscriptions, connexions et changements de mot de passe ont une validation serveur stricte et une limitation persistée en base.
- La capacité est calculée sur tout le service, avec verrouillage PostgreSQL pour sérialiser les écritures concurrentes.
- Les dates passées, créneaux fermés, horaires invalides et modifications incompatibles avec des réservations existantes sont refusés.
- Le preview utilise une URL d'API et des secrets distincts du domaine de production.

## Vérifications locales

- PHPUnit : 28 tests, 419 assertions, sur SQLite mémoire et PostgreSQL local dédié.
- Playwright : 15 tests séquentiels, dont les parcours galerie, administration, sessions et réservations.
- Migrations PostgreSQL : exécutées sur une base de test neuve ; la migration de session inspecte la base réelle avant d'ajouter ses changements.

## Déploiement

Le staging Heroku est provisionné séparément, sans données de production. Le code doit encore être publié sur ce staging, puis vérifié avant toute promotion en production. La restauration d'une sauvegarde de production n'a pas été exécutée : les sauvegardes restent chez Heroku conformément à la décision du propriétaire.
