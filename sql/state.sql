CREATE TABLE IF NOT EXISTS geo_build_state (
    role text PRIMARY KEY CHECK (role IN ('next', 'current', 'previous')),
    build_id text NOT NULL,
    format_version integer NOT NULL,
    activated_at timestamptz
);
