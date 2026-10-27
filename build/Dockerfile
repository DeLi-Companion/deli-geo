# syntax=docker/dockerfile:1

FROM ubuntu:24.04 AS osmium
ARG PROTOZERO_VERSION=1.8.2
ARG LIBOSMIUM_VERSION=2.23.1
ARG OSMIUM_TOOL_VERSION=1.19.1
ARG BUILD_JOBS=2
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install --yes --no-install-recommends \
        ca-certificates cmake curl g++ make \
        libboost-program-options-dev libbz2-dev libexpat1-dev liblz4-dev nlohmann-json3-dev zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /src
RUN curl --fail --silent --show-error --location "https://github.com/mapbox/protozero/archive/refs/tags/v${PROTOZERO_VERSION}.tar.gz" | tar --extract --gzip \
    && curl --fail --silent --show-error --location "https://github.com/osmcode/libosmium/archive/refs/tags/v${LIBOSMIUM_VERSION}.tar.gz" | tar --extract --gzip \
    && curl --fail --silent --show-error --location "https://github.com/osmcode/osmium-tool/archive/refs/tags/v${OSMIUM_TOOL_VERSION}.tar.gz" | tar --extract --gzip \
    && cmake -S "osmium-tool-${OSMIUM_TOOL_VERSION}" -B build \
        -DCMAKE_BUILD_TYPE=Release \
        -DBUILD_TESTING=OFF \
        -DOSMIUM_INCLUDE_DIR="/src/libosmium-${LIBOSMIUM_VERSION}/include" \
        -DPROTOZERO_INCLUDE_DIR="/src/protozero-${PROTOZERO_VERSION}/include" \
    && cmake --build build --parallel "${BUILD_JOBS}" \
    && cmake --install build --strip

FROM ubuntu:24.04 AS pmtiles
ARG PMTILES_VERSION=1.31.2
ARG PMTILES_SHA256=3ed7dbf4ec2e6dfe5e25b6f70d1ffc932729f93c86db353bf514dd71010a312f
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install --yes --no-install-recommends ca-certificates curl \
    && rm -rf /var/lib/apt/lists/*
RUN curl --fail --silent --show-error --location --output /tmp/pmtiles.tar.gz \
        "https://github.com/protomaps/go-pmtiles/releases/download/v${PMTILES_VERSION}/go-pmtiles_${PMTILES_VERSION}_Linux_x86_64.tar.gz" \
    && echo "${PMTILES_SHA256}  /tmp/pmtiles.tar.gz" | sha256sum --check - \
    && tar --extract --gzip --file /tmp/pmtiles.tar.gz --directory /usr/local/bin pmtiles

FROM ghcr.io/valhalla/valhalla:3.9.1
LABEL org.opencontainers.image.source="https://github.com/DeLi-Companion/deli-geo" \
      org.opencontainers.image.description="DeLi-Companion geo build: addresses, postcode areas, Valhalla tiles and map extract"
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install --yes --no-install-recommends \
        ca-certificates jq libboost-program-options1.83.0 libbz2-1.0 libexpat1 liblz4-1 zlib1g \
    && rm -rf /var/lib/apt/lists/*
COPY --from=osmium /usr/local/bin/osmium /usr/local/bin/osmium
COPY --from=pmtiles /usr/local/bin/pmtiles /usr/local/bin/pmtiles
COPY config/ /opt/deli-geo/config/
COPY regions/ /opt/deli-geo/regions/
COPY scripts/ /opt/deli-geo/scripts/
COPY smoke/ /opt/deli-geo/smoke/
ENV HOME=/tmp
USER 1000:1000
ENTRYPOINT ["/opt/deli-geo/scripts/build.sh"]
