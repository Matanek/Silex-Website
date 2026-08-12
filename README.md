# Silex website and package registry

This repository publishes the minimal Silex website and the versioned public
package registry through GitHub Pages.

- Website: `https://matanek.github.io/Silex-Website/`
- Registry v1: `https://matanek.github.io/Silex-Website/registry/v1/index.json`

Package archives remain owned and published by their respective GitHub
repositories. The registry contains their immutable release URLs, compatibility
ranges, and SHA-256 checksums.

## Registry layout

Each published version has its own immutable manifest. Publishing one package
does not modify another package's files. Per-package indexes are generated in
the GitHub Pages artifact and are never committed.

```text
registry/v1/
  index.json
  packages/
    STD/
      0.16.1.json
    GFX/
      0.23.1.json
```

The registry index only describes how clients resolve package URLs:

- `packages/{package}/index.json` lists releases and their Silex compatibility;
- `packages/{package}/{version}.json` describes one immutable release.

A publication pull request adds only its version manifest. During deployment,
`scripts/build-registry.mjs` validates every manifest and generates each
package's `index.json`, ordered from the newest semantic version to the oldest.
Silex can then select the newest release compatible with the local toolchain.
