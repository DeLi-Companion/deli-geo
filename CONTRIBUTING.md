# Contributing

Thank you for helping. Bug reports, address spellings the normalizer misses and pull requests are welcome.

## Development

You need Docker with the Compose plugin. The development stack runs PHP 8.5 and PostgreSQL with PostGIS:

```bash
docker compose run --rm php composer install
docker compose run --rm php composer check     # Pint, PHPStan, Pest (unit and integration tests)
docker compose run --rm php composer format    # fix the code style
```

The integration tests need `DELI_GEO_TEST_DSN`; the stack sets it. A skipped test counts as a failure, so the database tests never pass without running.

Shell scripts must pass shellcheck:

```bash
docker run --rm --volume "$PWD:/repository" --workdir /repository koalaman/shellcheck:v0.11.0 \
    --external-sources --source-path=SCRIPTDIR build/scripts/*.sh build/smoke/*.sh
```

The geo build image, the Berlin build, Valhalla, VROOM and the smoke test run in CI on every pull request. Building the image locally compiles osmium-tool; keep `--build-arg BUILD_JOBS=2` (the default) on machines with less than 16 GB of memory.

## Conventions

- `declare(strict_types=1)`, final classes, typed everything; the library depends on PHP extensions and PDO only.
- Full descriptive names, no abbreviations.
- Commits follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/): `feat(loader): load postcode areas`.
- A change to the build format raises `format_version` in `build/scripts/publish.sh` and `FormatVersion::CURRENT` together, and is documented in `docs/format.md`.

## Licence of contributions

By submitting a contribution you agree that it is licensed under the Apache License 2.0, as described in section 5 of the [licence](LICENSE).
