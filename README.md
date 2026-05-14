Project Title: CRUD Skeleton

Small, modern PHP CRUD skeleton and service layer utilities.

Overview
--------
This repository contains a compact PHP project scaffold focused on service-layer patterns for CRUD operations using Doctrine ORM and Symfony components. It is intended as a starting point for building APIs and internal services with a clear separation of responsibilities.

Key Principles
---------------
- Minimal, pragmatic building blocks for services.
- Clear separation of concerns: infrastructure helpers, read/list logic and mutation logic are split for easier testing and maintenance.
- Backwards-compatible helpers (for serializer, request access) so the code works both in legacy and modern Symfony containers.

Highlights
----------
- Doctrine ORM based repository and query builder helpers
- A BaseService abstraction (now composed from small traits) to provide common CRUD patterns
- Lightweight fake objects in tests to allow unit testing services without booting a framework kernel

Requirements
------------
- PHP >= 8.4
- Composer
- The project expects Symfony components and Doctrine packages (see composer.json). For development and running tests you should have dev dependencies installed via Composer.

Installation
------------
1. Clone the repository:

   git clone <repo-url>
   cd crud-skeleton

2. Install dependencies:

   composer install

3. Prepare environment (if you will run the integration tests):
   - Configure a database connection in your .env or Symfony config
   - Run migrations / schema setup as appropriate for the chosen DB

Usage
-----
This skeleton is primarily focused on the service layer. Typical usage patterns:

- Build a concrete service by extending App\Core\Service\BaseService and passing the entity class in the constructor.
- Use the service to create, read, update and delete entities via provided methods: new(), get(), list(), update(), updateWithoutListener(), remove().

Example
-------
Minimal example (pseudocode):

   $service = new ContentBaseService($container);
   $entity = $service->new();
   $service->update($entity, ['title' => 'Hello']);
   $fetched = $service->get($entity->getId());

Testing
-------
Unit tests in this repository use PHPUnit. To run the tests:

1. Ensure your local PHP version meets the requirement (see composer.json). PHPUnit used by the repo expects PHP >= 8.3 or as declared by composer dev dependencies.
2. Install dev dependencies: composer install --dev
3. Run tests:

   ./vendor/bin/phpunit

If you encounter a PHP version mismatch when running phpunit locally (for example older system PHP), consider using a tool like phpenv, Docker, or a container image that provides the required PHP version.

Repository Layout
-----------------
- src/ - application source code
  - Core/Service - base service and supporting traits & helpers
- tests/ - unit and integration tests
- composer.json - dependency and autoload configuration

Contributing
------------
Contributions are welcome. When contributing:

1. Open a feature branch from main.
2. Keep changes focused and small; prefer small helpers to monolithic changes.
3. Run tests locally and ensure static checks (if any) pass.
4. Create a pull request with a clear title and description explaining the "why" not only the "what".

Versioning & Releases
---------------------
This repository follows semantic versioning for releases. For GitHub Releases provide a changelog entry summarising the notable changes.

License
-------
The repository contains a proprietary license placeholder in composer.json. Confirm licensing with repository owners before redistributing.

Contact
-------
If you have questions or need assistance, open an issue or contact the maintainers listed on the repository.

Appendix: Notes about BaseService refactor
-----------------------------------------
To make the code easier to maintain and test the monolithic BaseService was refactored into smaller traits, grouped by responsibility:

- Concern/BaseServiceInfrastructureTrait — infrastructure helpers (serializer, logger, entity manager access, etc.)
- Concern/BaseServiceReadListTrait — read/list/query logic (the former list/get behaviors)
- Concern/BaseServiceMutationTrait — create/update/remove and metadata handling

This refactor is internal: public API (BaseServiceInterface and method signatures) were kept intact so existing callers should continue to work.
