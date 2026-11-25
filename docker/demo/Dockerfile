# Carbure demonstration: the regular image with MariaDB inside and demonstration data
# loaded at each start (and again each day), to try Carbure in one command:
#   docker run --rm -p 8080:80 ltoinel/carbure:demo
# then http://localhost:8080/portal/ (demo / carbure; the password of admin is printed
# in the logs, new at each start). Not for real data: public password, everything is wiped at each start.
#
# Built on top of the regular image (docker/Dockerfile), from the root of the project:
#   docker build -f docker/demo/Dockerfile --build-arg BASE=ltoinel/carbure:latest -t ltoinel/carbure:demo .

ARG BASE=ltoinel/carbure:latest
# hadolint ignore=DL3006
FROM ${BASE}

LABEL org.opencontainers.image.title="Carbure (demo)" \
      org.opencontainers.image.description="Carbure demonstration: MariaDB and demonstration data inside, wiped at each start"

# MariaDB server and client of the distribution of the regular image (Alpine)
# hadolint ignore=DL3018
RUN apk add --no-cache mariadb mariadb-client \
    && mkdir -p /run/mysqld \
    && chown mysql:mysql /run/mysqld

# Demonstration data: the one of the development stack (./start.sh), the tests are not
# in the regular image
COPY --chown=www-data:www-data tests/e2e/server/seed.php /var/www/carbure/tests/e2e/server/seed.php
COPY --chmod=755 docker/demo/entrypoint.sh /var/www/carbure/docker/demo/entrypoint.sh

ENTRYPOINT ["/var/www/carbure/docker/demo/entrypoint.sh"]
CMD ["nginx", "-g", "daemon off;"]
