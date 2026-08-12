# Silex website

This repository owns the Silex website. GitHub Pages remains the production
host during the VPS migration:

- Website: `https://matanek.github.io/Silex-Website/`
- Production domain: `https://silex-lang.org/`

The public package registry lives in its own repository at
`https://github.com/Matanek/Silex-Registry` and is served independently from
`https://registry.silex-lang.org/`.

## Migration status

`pages.yml` publishes only the website during the migration. The independent
VPS workflow is disabled until the repository variable `VPS_DEPLOY_ENABLED` is
set to `true`.

See [deploy/README.md](deploy/README.md) for configuration and the cutover
checklist.
