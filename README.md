# deli-geo

German address lookup on PostGIS and the geo build behind [DeLi-Companion](https://github.com/DeLi-Companion): a small PHP library that normalizes, loads and looks up OpenStreetMap addresses, and a Docker image that builds addresses, postcode areas, Valhalla routing tiles and a map extract from an OpenStreetMap extract.

No external geocoding or routing API is involved: everything runs on your own servers.

## Library

Requirements: PHP 8.5 with `intl`, `mbstring`, `pdo_pgsql` and `zlib`; PostgreSQL with PostGIS and `pg_trgm`.

deli-geo is not on Packagist; Composer installs it from the GitHub repository by version tag:

```bash
composer config repositories.deli-geo vcs https://github.com/DeLi-Companion/deli-geo
composer require deli-companion/deli-geo:0.1.0
```

```php
use DeLiCompanion\Geo\Loading\AddressLoader;
use DeLiCompanion\Geo\Loading\GeoSchema;
use DeLiCompanion\Geo\Lookup\AddressLookup;
use DeLiCompanion\Geo\Lookup\AddressQuery;
use DeLiCompanion\Geo\Manifest\BuildFinder;

$connection = PDO::connect('pgsql:host=localhost;dbname=geo;user=geo;password=secret');

(new GeoSchema($connection))->createLiveTables();      // once: extensions, empty tables, indexes

$manifest = (new BuildFinder)->newest('/srv/geo/builds'); // newest build this library can read
if ($manifest === null) {
    throw new RuntimeException('No geo build this library can read in /srv/geo/builds.');
}
$loader = new AddressLoader($connection);
$loader->load($manifest);       // verify checksums, load into geo_addresses_next and geo_streets_next
$loader->activate($manifest);   // swap in one transaction; the old tables become *_previous
// $loader->rollback();         // back to the previous build

$result = (new AddressLookup($connection))->find(new AddressQuery('10115', 'Invalidenstr.', '43'));
$result->kind;            // MatchKind::Exact, FuzzyStreet, StreetCentre, Ambiguous or NoMatch
$result->needsReview();   // true for everything but an exact match
$result->latitude;
$result->longitude;
```

The lookup cascade:

1. Exact postcode, normalized street and normalized house number — accepted.
2. Most similar street within the postcode (trigram similarity ≥ 0.6 by default), then the exact house number — needs review.
3. The street centre when the house number is missing or unknown — needs review.
4. Ambiguous when the address, or the street without an address hit, exists in several places within the postcode (a rural postcode often covers several villages, each with its own "Dorfstraße 5"): the first candidate by latitude and longitude — needs review.
5. No match.

Normalization folds case, umlauts and ß, unifies "Straße", "Strasse" and "Str.", ignores spaces, hyphens and dots, and parses house numbers such as "12a", "12 A", "12-14" and "12/1". A four-digit postcode gets back the leading zero a spreadsheet dropped ("1067" becomes "01067"). The same normalizers run when loading and when looking up, so a changed rule and the loaded data always come from one library version.

A load refuses a build with checksum errors, an unsupported `format_version`, no usable address or postcode area, or fewer than half the live addresses, and it refuses to run while another load, activation or rollback holds the advisory lock. The live tables stay untouched in every case.

## Geo build

The `geo-build` image (`ghcr.io/deli-companion/geo-build:<commit sha>`) runs one complete build and exits:

1. Download the OpenStreetMap extract, only when the upstream file is newer.
2. Export every object with `addr:housenumber` and every postcode boundary.
3. Build Valhalla tiles for the configured regions.
4. Cut the map from a [Protomaps](https://protomaps.com) daily build and add glyphs and sprites.
5. Publish a dated build directory with `manifest.json` and `SHA256SUMS`; keep the newest six.

| Variable | Default | Meaning |
| --- | --- | --- |
| `DELI_GEO_SOURCE_URL` | `https://download.geofabrik.de/europe/germany-latest.osm.pbf` | OpenStreetMap extract |
| `DELI_GEO_REGIONS` | `berlin` | Regions for tiles and map, from `build/regions/<name>.bbox` |
| `DELI_GEO_DATA_DIRECTORY` | `/data` | Holds `cache/`, `work/` and `builds/` |
| `DELI_GEO_BUILD_ID` | today (UTC) | Name of the build directory |
| `DELI_GEO_BUILD_JOBS` | `2` | Parallel jobs for the tile build |
| `DELI_GEO_KEEP_BUILDS` | `6` | Builds to keep |
| `DELI_GEO_BASEMAP_URL` | newest daily build of the last seven days | Protomaps build to cut the map from |

The format of a build is described in [docs/format.md](docs/format.md).

`build/scripts/activate_tiles.sh BUILDS_DIRECTORY BUILD_ID` points the tiles link `DELI_GEO_TILES_LINK` at a build, recreates the Valhalla container of the Compose file `DELI_GEO_COMPOSE_FILE` (both required), runs `build/smoke/smoke.sh` (matrix, route and one VROOM optimization) and switches back to the previous build when the smoke test fails.

### Example stack

[examples/compose.yaml](examples/compose.yaml) runs Valhalla 3.9.1, VROOM 1.15.0 on Valhalla and the build image. `GEO_BUILD_IMAGE` must name a published commit tag; there is no `latest` tag.

```bash
cd examples
mkdir -p data
GEO_BUILD_IMAGE=ghcr.io/deli-companion/geo-build:<commit sha> docker compose --profile build run --rm geo-build
latest_build="$(ls -1 data/builds | sort | tail -n 1)"
ln -sfn "$PWD/data/builds/${latest_build}/valhalla" data/current
docker compose up --detach --wait valhalla vroom
../build/smoke/smoke.sh
```

A Berlin build needs about 4 GB of memory and a few minutes. Germany-wide builds need about 20 GB of free disk: the extract and its re-download, the filtered files, the node index and six kept builds.

## Licences

The code is licensed under the [Apache License 2.0](LICENSE).

Image contents: the `geo-build` image bundles the deli-geo build scripts (Apache-2.0); the Valhalla 3.9.1 base image (MIT); osmium-tool 1.19.1 (GPL-3.0), built unmodified from <https://github.com/osmcode/osmium-tool/tree/v1.19.1> with libosmium 2.23.1 (BSL-1.0) and protozero 1.8.2 (BSD-2-Clause); and go-pmtiles 1.31.2 (BSD-3-Clause). The exact sources are the tagged upstream releases pinned in [build/Dockerfile](build/Dockerfile).

All data produced by the geo build is derived from OpenStreetMap: © OpenStreetMap contributors, available under the [Open Database License](https://opendatacommons.org/licenses/odbl/). If you distribute a derived database publicly, you must offer it under the ODbL as well. Maps must show "© OpenStreetMap contributors". The map extract comes from Protomaps basemap builds, which are derived from OpenStreetMap; the glyphs are Noto Sans under the SIL Open Font License (`map/fonts/OFL.txt` in every build).
