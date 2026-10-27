CREATE INDEX IF NOT EXISTS geo_addresses{suffix}_lookup_index
    ON geo_addresses{suffix} (postal_code, street_name_normalized, house_number_normalized);

CREATE INDEX IF NOT EXISTS geo_addresses{suffix}_location_index
    ON geo_addresses{suffix} USING gist (location);

CREATE INDEX IF NOT EXISTS geo_streets{suffix}_lookup_index
    ON geo_streets{suffix} (postal_code, street_name_normalized);

CREATE INDEX IF NOT EXISTS geo_streets{suffix}_street_name_trigram_index
    ON geo_streets{suffix} USING gin (street_name_normalized gin_trgm_ops);
