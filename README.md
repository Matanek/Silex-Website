# Silex website

This repository owns the Silex website. GitHub Pages remains the production
host during the VPS migration:

- Website: `https://matanek.github.io/Silex-Website/`
- Production domain: `https://silex-lang.org/`

The public package registry now has its own repository at
`https://github.com/Matanek/Silex-Registry`. The legacy registry copy remains
here temporarily so the current GitHub Pages deployment keeps serving existing
Silex clients until the VPS cutover is complete.

## Migration status

`pages.yml` continues to publish the site and legacy registry. The independent
VPS workflow builds only the website and is disabled until the repository
variable `VPS_DEPLOY_ENABLED` is set to `true`.

See [deploy/README.md](deploy/README.md) for configuration and the cutover
checklist.
