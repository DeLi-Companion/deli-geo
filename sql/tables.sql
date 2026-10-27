CREATE TABLE IF NOT EXISTS geo_addresses{suffix} (
    street_name text NOT NULL,
    street_name_normalized text NOT NULL,
    house_number text NOT NULL,
    house_number_normalized text NOT NULL,
    postal_code text NOT NULL,
    city text,
    location geometry(Point, 4326) NOT NULL
);

CREATE TABLE IF NOT EXISTS geo_streets{suffix} (
    street_name text NOT NULL,
    street_name_normalized text NOT NULL,
    postal_code text NOT NULL,
    city text,
    location geometry(Point, 4326) NOT NULL
);
