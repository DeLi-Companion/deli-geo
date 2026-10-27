# Geo build format

A geo build is one directory named after its UTC build date (`YYYY-MM-DD`). Consumers only use a directory that contains `manifest.json`; the build is written to `.<build id>.partial/` first and renamed when complete.

## Format version 1

```
2026-10-27/
├── manifest.json
├── SHA256SUMS
├── addresses.geojsonseq.gz
├── postcode-areas.geojsonseq.gz
├── valhalla/
│   ├── valhalla_tiles.tar
│   ├── admins.sqlite
│   └── valhalla.json
└── map/
    ├── map.pmtiles
    ├── fonts/        MapLibre glyph ranges: Noto Sans Regular, Medium, Italic, OFL.txt
    └── sprites/      v4 light and dark sprites (1x and 2x)
```

### `manifest.json`

| Field | Type | Meaning |
| --- | --- | --- |
| `format_version` | integer | Version of this format, currently `1` |
| `build_id` | string | Build date `YYYY-MM-DD`; equals the directory name |
| `created_at` | string | UTC time the build finished, `YYYY-MM-DDTHH:MM:SSZ` |
| `regions` | array | Regions covered by tiles and map: `{ "name": "berlin", "bbox": [min_lon, min_lat, max_lon, max_lat] }` |
| `sources` | object | Where the data came from: `osm` (`url`, `md5`, `last_modified`), `basemap` (`url`), `assets` (`url`, `sha256`) |
| `files` | object | Role → path relative to the build directory: `addresses`, `postcode_areas`, `valhalla_tiles`, `valhalla_admins`, `valhalla_config`, `map`, `map_fonts`, `map_sprites` |
| `checksums_file` | string | `SHA256SUMS`: SHA-256 of every file in the build, in `sha256sum` format with paths relative to the build directory |

### Data files

`addresses.geojsonseq.gz` and `postcode-areas.geojsonseq.gz` are gzip-compressed GeoJSON text sequences: one GeoJSON `Feature` per line (RFC 8142 without the record separator; readers tolerate a leading `0x1E`). Coordinates are WGS 84 (EPSG:4326).

- Addresses: every OpenStreetMap node, way or relation with `addr:housenumber`, as `Point` or `(Multi)Polygon`. Properties are limited to `addr:housenumber`, `addr:street`, `addr:place`, `addr:postcode` and `addr:city`.
- Postcode areas: every `boundary=postal_code` relation as `(Multi)Polygon` with its `postal_code` property. The loader uses them to fill a missing `addr:postcode`.

`valhalla/valhalla.json` expects the `valhalla/` directory mounted at `/data` inside the Valhalla container.

## Versioning

- A change that existing readers cannot read raises `format_version`.
- The geo build raises `format_version` only after the consuming application runs a library version that reads it.
- The loader accepts the current version N and N−1, so a build and an application can be updated independently.

## Licences

Address, postcode, routing and map data are derived from OpenStreetMap: © OpenStreetMap contributors, available under the Open Database License (ODbL). A publicly distributed derived database must be offered under the ODbL as well. The fonts are Noto Sans under the SIL Open Font License (`map/fonts/OFL.txt`).
