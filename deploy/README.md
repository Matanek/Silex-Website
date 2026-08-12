# VPS deployment

The VPS workflow publishes the site under
`/srv/silex/website/releases/<git-sha>` and atomically changes the `current`
symlink. It is disabled until `VPS_DEPLOY_ENABLED` is set to `true`.

Configure the same SSH settings described by the Silex Registry deployment:

- secrets: `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`, `VPS_KNOWN_HOSTS`;
- optional variables: `VPS_SSH_PORT`, `VPS_WEBSITE_ROOT`;
- enable variable: `VPS_DEPLOY_ENABLED=true`.

The default site root is `/srv/silex/website`. The deployment account needs
write access to that directory but does not need root access.

GitHub Pages remains enabled during migration. Remove `pages.yml` only after all
of these are true:

1. both website and registry releases exist on the VPS;
2. `registry.silex-lang.org` serves the independent registry release root;
3. the registry URL and the site pass smoke tests;
4. `silex install STD` succeeds against the production URL;
5. DNS rollback remains available during the observation window.
