# Silex Website

This repository owns the Silex website implementation. The public production
deployment is available at <https://silex-lang.org/>; the historical
<https://silex.nekmata.com/> address serves the same immutable release.

The website renders the public language and tool documentation owned by
`Matanek/Silex-Documentation` and the bilingual release notes owned by
`Matanek/Silex`. It must not become a second editable copy of those Markdown
sources.

In a Silex workspace checkout, the application automatically reads the sibling
`Silex-Documentation/`, `Silex/`, `Silex-Registry/`, and `Packages/`
directories. Override those sources for another environment with
`SILEX_DOCUMENTATION_ROOT`, `SILEX_SOURCE_ROOT`, `SILEX_REGISTRY_ROOT`, and
`SILEX_PACKAGES_ROOT`.
The `/fr/` and `/en/` route trees read the mirrored `FR/` and `EN/`
documentation trees. The root route uses the saved preference, then the
browser language, and exposes a switch that preserves the current page.

Production releases contain an immutable snapshot under `var/content/sources`.
The deployment build fetches the Silex release, its matching documentation
branch, the canonical VS Code TextMate grammar, the registry, and the
registered package manifests. It copies both
documentation languages, immutable package registrations, and only the
manifests needed for plain or localized package descriptions; package
documentation is not ingested. Package cards link directly to each canonical
repository. The browser bundle uses Shiki to highlight `sx` Markdown fences
from that grammar without maintaining a second editable lexer in this
repository. Local workspace sources keep priority so Herd reflects live
documentation and manifest changes without rebuilding that snapshot.

The `/fr/releases` and `/en/releases` routes paginate the canonical Silex
release notes. A release candidate must add matching French and English entries
with an explicit upgrade rationale, change list, and migration impact. The
Silex release workflow validates those entries and also uses the English entry
as the GitHub Release body. Its website-refresh job dispatches `deploy.yml`,
awaits that exact run, and checks the requested version in both public locales.
A failed refresh is retried independently without rebuilding or retagging the
compiler release. This editorial history starts at 0.44.1;
it is not an exhaustive listing of older Git tags. Pending `Unreleased` notes
are not published as releases. Before dispatching a new minor Silex release,
publish its matching `Silex-Documentation` branch (`release/<major>.<minor>`).
After deployment, verify the displayed version and both release-note routes;
a successful branch push alone does not establish that the notes are online.

The deployment runs for website pushes, manual requests, ecosystem content
dispatches, and Silex releases. Its immutable release identifier combines the
website commit with the content-snapshot digest.

The displayed Silex version resolves, in order, from `SILEX_VERSION`, locally
from `../Silex/Toolchain/build.zig.zon`, or from the immutable release file
`var/content/silex-version.txt`. A Silex release hook can therefore publish
content and version metadata without making this repository their owner.

The application deliberately uses no database. Slim 4 owns HTTP routing and
middleware, Twig renders pages, League CommonMark converts documentation, and
Tailwind CSS produces the static stylesheet.

## Requirements

- PHP 8.2 or newer;
- Composer 2;
- Node.js and npm for the Tailwind and syntax-highlighting builds;
- a sibling `Silex-Extension-VSCode/` checkout, the fetched `.content/` tree,
  or an explicit `SILEX_TEXTMATE_GRAMMAR` path for the canonical grammar.

## Validate

```sh
composer install
npm ci
npm run build
npm run content:build
composer check
```

Start the local front controller with:

```sh
composer serve
```

The website is then available at <http://127.0.0.1:8080/>. For live CSS
rebuilds, run `npm run dev` in a second terminal. Rebuild the syntax bundle
after a grammar change with `npm run build:syntax`, or watch it with
`npm run dev:syntax`. With Laravel Herd, link the `public/` directory under an
explicit name such as `silex`.

## Deployment

Every push to `main` validates the exact commit, uploads an immutable release
to the VPS, atomically updates the `current` symlink, and checks the production
and historical HTTPS endpoints. See [deploy/README.md](deploy/README.md) for
the server contract and required GitHub settings.
