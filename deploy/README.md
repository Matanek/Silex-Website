# VPS deployment

GitHub Actions deploys each validated `main` commit as an immutable release:

```text
/srv/silex/web/
  current -> /srv/silex/web/releases/<website-sha>-<content-digest>
  releases/
    <website-sha>-<content-digest>/
      public/
      var/content/
        silex-version.txt
        sources/
          Silex-Documentation/
            EN/
            FR/
          Silex-Registry/
          Packages/
            <package>/Package.json
```

Apache serves `/srv/silex/web/current/public` for
`https://silex-lang.org/` and `https://silex.nekmata.com/`. The deployment
account owns only `/srv/silex/web`; it has no sudo privileges and does not own
the Apache configuration.

The expected virtual host is versioned in
`deploy/apache/silex.nekmata.com.conf`. Updating that file does not change the
server automatically: Apache configuration remains an explicit administrator
operation, separate from application deployments.

The production virtual host source is versioned in
`deploy/apache/silex-lang.org.conf`. Certbot owns its deployed HTTPS
augmentation and certificate renewal on the VPS.

The dedicated PHP-FPM pool is versioned in `deploy/php/silex-web.conf`. Its
OPcache settings revalidate the real path behind the atomic `current` symlink;
without this, PHP-FPM may continue serving a previous release after a switch.

The VPS requires OpenSSH, `rsync`, Apache, and PHP 8.2-FPM. The GitHub-hosted
runner requires PHP, Composer, Node.js, npm, OpenSSH, and `rsync`. Tailwind is
built by GitHub Actions; Node.js is not required on the VPS.

## GitHub secrets

- `VPS_HOST`: VPS hostname or address;
- `VPS_USER`: dedicated deployment account;
- `VPS_SSH_KEY`: private Ed25519 deployment key;
- `VPS_KNOWN_HOSTS`: pinned SSH host-key entry.

Optional GitHub variables:

- `VPS_SSH_PORT`, default `22`;
- `VPS_SITE_ROOT`, default `/srv/silex/web`.

The workflow fetches the latest semantic Silex release, its matching
`Silex-Documentation` release branch, and current immutable registrations from
the registry. It copies the mirrored `EN/` and `FR/` Markdown trees, the
registry entries, and each registered package manifest into the immutable
release. The manifests supply the canonical plain or localized package
descriptions; package sources and documentation are never copied. Catalog
links lead to their canonical repositories.

Website pushes, manual runs, `ecosystem-content-updated` dispatches, and
`silex-released` dispatches rebuild the snapshot immediately. The release
directory combines the website SHA with the snapshot digest, making a
content-only refresh immutable and independently deployable.

The Silex release workflow calls `deploy.yml` with the `silex_version` input.
The build rejects a snapshot with a different version, and the public checks
verify that version on both release-note routes. The source repository stores
a dedicated `WEBSITE_DISPATCH_TOKEN` limited to this website repository with
**Actions: read and write**. It does not need Contents write access: workflow
dispatch and repository dispatch have different permission requirements.
Never reuse an interactive developer token as this automation credential.

Silex waits for the exact deployment run returned by GitHub. Missing credentials,
a rejected dispatch, a failed deployment, or a timeout fail the separate website
job; the already published compiler release is not rolled back or retagged.
After fixing the cause, dispatch Silex's `website-refresh.yml` with the published
version. This exercises the same unattended path as a release. A manual run in
this repository alone is a fallback, not proof that the cross-repository hook
works.
