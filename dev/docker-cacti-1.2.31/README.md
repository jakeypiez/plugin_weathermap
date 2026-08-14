# Cacti 1.2.31 Weathermap development harness

This Compose stack runs the working checkout against Cacti 1.2.31 and MariaDB
11.4. Both upstream container images are pinned by multi-platform digest. The
Cacti source is pinned to the commit behind the `release/1.2.31` tag and its
downloaded archive is verified with SHA-256 during the image build.

## Start

From this directory:

```sh
docker compose up --build -d
docker compose ps
```

Open <http://127.0.0.1:8088/> and sign in with:

- Username: `admin`
- Password: `cacti-dev`

Copy `.env.example` to `.env` before startup if you need a different loopback
port or credentials. The site is deliberately bound only to `127.0.0.1`.

The one-shot `bootstrap` service imports Cacti, completes its CLI installer,
installs and enables Weathermap, grants its realms to administrators, and sets
the development password. It is idempotent and runs before Apache starts.

## Plugin mount and saved maps

The repository root is bind-mounted read-only at
`/var/www/html/plugins/weathermap`, so edits made in the checkout are loaded by
the next request without allowing the container to modify source files.

The plugin's `configs/` and `output/` paths are writable Docker volumes layered
over that bind mount. Their initial contents are seeded from the checkout. Map
edits and generated files therefore remain isolated from Git while surviving a
container rebuild.

To inspect a saved map:

```sh
docker compose exec web sed -n '1,160p' \
  /var/www/html/plugins/weathermap/configs/simple.conf
```

The `fixtures/relative-drag.conf` map exercises parent and child node movement.
Copy it into the isolated configuration volume when running editor regression
tests:

```sh
docker compose exec --user www-data web sh -lc \
  'cp /plugin-source/dev/docker-cacti-1.2.31/fixtures/relative-drag.conf \
  /var/www/html/plugins/weathermap/configs/relative-drag.conf'
```

`fixtures/include-map.conf` and `fixtures/included-node.inc` exercise the
locked-node treatment for elements owned by an included configuration:

```sh
docker compose exec --user www-data web sh -lc \
  'cp /plugin-source/dev/docker-cacti-1.2.31/fixtures/include-map.conf \
  /plugin-source/dev/docker-cacti-1.2.31/fixtures/included-node.inc \
  /var/www/html/plugins/weathermap/configs/'
```

## Useful commands

```sh
# Follow application, bootstrap, and database logs.
docker compose logs -f web bootstrap db

# Re-run the idempotent bootstrap checks.
docker compose run --rm bootstrap

# Show the installed versions and plugin state.
docker compose exec web sh -lc \
  'cat include/cacti_version; php cli/plugin_manage.php --help | head'
docker compose exec db sh -lc \
  'mariadb -ucacti -p"$MARIADB_PASSWORD" cacti \
  -e "SELECT directory, status, version FROM plugin_config"'

# Stop services but keep database and saved-map volumes.
docker compose down

# Full local reset. This removes only volumes owned by this Compose project.
docker compose down -v
```

After a full reset, `docker compose up -d` recreates the database and reseeds
`configs/simple.conf` from the checkout.
