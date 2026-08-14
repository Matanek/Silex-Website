# Silex website

This repository owns the Silex website. GitHub Pages remains the production
host during the VPS migration:

- Website: `https://matanek.github.io/Silex-Website/`
- Production domain: `https://silex-lang.org/`

The public package registry lives in its own repository at
`https://github.com/Matanek/Silex-Registry` and is served independently from
`https://registry.silex-lang.org/`.

## Current Silex release

The home page displays the latest published Silex version. The Pages workflow
resolves it from the latest GitHub release during regular deployments and its
daily synchronization run.

After publishing a tagged release, the Silex release workflow can request an
immediate website rebuild. Configure a fine-grained personal access token as
the `WEBSITE_DISPATCH_TOKEN` secret in the `Matanek/Silex` repository. Scope
the token to `Matanek/Silex-Website` with write access to repository contents.
If the secret is absent, publishing still succeeds and the daily Pages build
provides the fallback synchronization.

## Documentation

`Silex/Docs` remains the source of truth for documentation because it must stay
versioned with the compiler behavior it describes. The website build reads that
directory and publishes:

- semantic HTML under `https://silex-lang.org/docs/`;
- the unchanged Markdown sources under `/docs/raw/`;
- AI discovery indexes at `/llms.txt` and `/llms-full.txt`;
- `sitemap.xml` and `robots.txt` for crawlers.

For a local checkout where `Silex` and `Silex-Website` are siblings, run:

```sh
npm install
npm run build
npm run check
```

The deployment workflows check out `Matanek/Silex` directly. Their daily run
keeps the published documentation synchronized even when the website itself has
not changed.

## Migration status

`pages.yml` publishes only the website during the migration. The independent
VPS workflow is disabled until the repository variable `VPS_DEPLOY_ENABLED` is
set to `true`.

See [deploy/README.md](deploy/README.md) for configuration and the cutover
checklist.
