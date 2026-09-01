# Store Application

This is the independently installable Store application boundary. It currently
contains only the Kernel and runtime configuration; Store business source remains
in the monolith until the extraction gates in
[`docs/design/store-extraction-readiness.md`](../../docs/design/store-extraction-readiness.md)
are satisfied.

## Local Boot

```bash
composer install
php bin/console about --env=test
```

The application owns only `App\Store\*` mapping. It must not import the monolith's
`src/Store` directory or its database mapping. Store entities, migrations, routes,
workers, and scheduled commands move here in later, separately verified steps.
