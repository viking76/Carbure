# Third-party notices

Carbure is released under the [MIT license](LICENSE) © Ludovic Toinel. It relies on
third-party software, distributed unmodified under its own license.

## Docker image

The Docker image (`ltoinel/carbure`) bundles:

| Component | License | Source |
|---|---|---|
| [woob](https://woob.tech) (bank synchronization) | LGPL-3.0-or-later | [gitlab.com/woob/woob](https://gitlab.com/woob/woob) (version set by `WOOB_VERSION` in `docker/Dockerfile`) |
| [curl_cffi](https://github.com/lexiforest/curl_cffi) | MIT | [github.com/lexiforest/curl_cffi](https://github.com/lexiforest/curl_cffi) |
| PHP, nginx, Alpine Linux | PHP License, BSD-2-Clause, various (BusyBox: GPL-2.0) | Official images `php:8.5.13-fpm-alpine` |

Carbure calls woob as a separate program; it can be replaced by another woob version
(virtual environment `/opt/woob`).

## Web portal

The libraries of the portal are served from [`portal/vendor/`](portal/vendor/README.md)
(versions and licenses listed there):

| Component | License |
|---|---|
| Vue.js | MIT |
| CodeMirror | MIT |
| Roboto | OFL-1.1 |
| Material Icons | Apache-2.0 |

## Teaser music

The music of the [teaser](https://www.youtube.com/watch?v=PUCd27oJkig) is
"Nowhere Land" by Kevin MacLeod ([incompetech.com](https://incompetech.com)), licensed
under [Creative Commons: By Attribution 4.0](https://creativecommons.org/licenses/by/4.0/).
