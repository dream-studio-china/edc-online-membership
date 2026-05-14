CRUD Skeleton (Symfony)
======================

Minimal, pragmatic CRUD skeleton built on Symfony. This repository contains a lightweight service layer (BaseService), controller helpers (RestController and API view mixins), a sample Content entity and service, and small serializer customizations used in API responses.

This README explains how to get the project running locally, common troubleshooting, and a few notes about the project's DI/service patterns.

Requirements
------------
- PHP >= 8.4
- Composer
- A supported database (MySQL, MariaDB, PostgreSQL) and its PHP extension
- Node/npm only if you use frontend assets (not required for backend API)

Quick Start
-----------
1. Install dependencies:

   composer install

2. Copy environment file and edit database settings:

   cp .env .env.local
   # Edit DATABASE_URL in .env.local

3. Create the database and run migrations (example using doctrine):

   php bin/console doctrine:database:create
   php bin/console doctrine:migrations:migrate

4. Run the dev server:

   symfony server:start

API endpoints
-------------
- GET /api/v1/manage/contents — list contents (management)
- POST /api/v1/app/contents — create content (app)

See src/Common/Controller for controllers and route attributes.

Testing
-------
- Run PHPUnit tests:

  ./vendor/bin/phpunit

Note: Your local PHP version must meet composer.json requirements (>= 8.4) to run console and tests.

Service / DI notes (important)
-----------------------------
- The project historically used direct Container access in services. To improve testability and robustness there's a small ServiceLocator abstraction at src/Core/Service/ServiceLocatorInterface and a DefaultServiceLocator implementation.
- BaseService tries to prefer a ServiceLocator (if provided) and falls back to creating a DefaultServiceLocator from the container. This allows gradual migration away from direct container usage while keeping backward compatibility.
- The project also provides a FlatNormalizer (src/Core/Serializer/Normalizer/FlatNormalizer.php) that decorates the object normalizer to produce flattened API output. That decorator is registered in src/Core/Resources/config/services.yaml.

Common runtime issues & troubleshooting
-------------------------------------
- "Serializer service is not available" — If you see this error, the BaseService fallback attempted to use framework serializer but it wasn't available in your runtime container. The project now builds a local fallback Serializer when needed, but you should ensure:
  - framework.serializer is enabled in config/packages/serializer.yaml (enabled: true)
  - serializer service is present in the container (php bin/console debug:container serializer)
  - PHP version meets composer requirements (>= 8.4) when running console commands

- "logger service removed or inlined" — modern Symfony compilation can inline or remove services. We alias LoggerInterface to the logger service in config/services.yaml and BaseService uses a NullLogger fallback when necessary.

Development guidance
--------------------
- Prefer explicit constructor injection for the concrete dependencies your service needs (EntityManager, LoggerInterface, RequestStack, SerializerInterface) rather than injecting the whole container.
- Use ServiceLocatorInterface when you need a centralized place to resolve many optional services; prefer it to anonymous container->get() calls scattered across the codebase.
- Keep serializer customizers (normalizers/encoders) registered in src/Core/Resources/config/services.yaml so the framework serializer composes them as expected.

Contributing
------------
- Fork the repo, create a branch, make small focused changes and open a PR with a clear description. Include tests for behavior changes when possible.

License
-------
Proprietary (see composer.json)

References
----------
- Symfony DI: https://symfony.com/doc/current/service_container.html
- Symfony Serializer: https://symfony.com/doc/current/components/serializer.html
