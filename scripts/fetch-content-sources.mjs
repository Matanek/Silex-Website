import { mkdir, readFile, readdir, rm, writeFile } from "node:fs/promises";
import { join, resolve } from "node:path";
import { spawnSync } from "node:child_process";
import { normalizePackageDescription } from "./package-description.mjs";

const outputRoot = resolve(process.argv[2] ?? ".content");
const packagePattern = /^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/;
const repositoryPattern = /^https:\/\/github\.com\/[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\.git$/;

function clone(repository, destination, reference = null, sparse = false) {
    const arguments_ = ["-c", "advice.detachedHead=false", "clone", "--quiet", "--depth=1", "--filter=blob:none", "--single-branch"];
    if (sparse) arguments_.push("--sparse");
    if (reference === null) {
        arguments_.push("--no-tags");
    } else {
        arguments_.push("--branch", reference);
    }
    arguments_.push(repository, destination);
    const result = spawnSync("git", arguments_, { stdio: "inherit" });
    if (result.status !== 0) {
        throw new Error(`Unable to fetch canonical content from ${repository}`);
    }
}

function latestVersionTag(repository) {
    const result = spawnSync("git", ["ls-remote", "--tags", "--refs", repository, "refs/tags/v*"], { encoding: "utf8" });
    if (result.status !== 0) throw new Error(`Unable to list published versions from ${repository}`);

    const versions = result.stdout
        .split("\n")
        .map((line) => line.match(/refs\/tags\/(v(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?)$/))
        .filter((match) => match !== null)
        .map((match) => ({ tag: match[1], major: Number(match[2]), minor: Number(match[3]), patch: Number(match[4]), prerelease: match[5] ?? null }));
    versions.sort((left, right) =>
        left.major - right.major
        || left.minor - right.minor
        || left.patch - right.patch
        || (left.prerelease === null ? 1 : right.prerelease === null ? -1 : left.prerelease.localeCompare(right.prerelease, "en")),
    );
    const latest = versions.at(-1);
    if (latest === undefined) throw new Error(`Canonical repository ${repository} has no published semantic version`);

    return latest;
}

function compareVersion(left, right) {
    return left.major - right.major || left.minor - right.minor || left.patch - right.patch;
}

function releasedChangelog(source, latestVersion, label) {
    const headings = [...source.matchAll(/^## \[(\d+)\.(\d+)\.(\d+)\] - \d{4}-\d{2}-\d{2}[ \t]*$/gm)];
    const selected = headings.filter((heading) => compareVersion({
        major: Number(heading[1]),
        minor: Number(heading[2]),
        patch: Number(heading[3]),
    }, latestVersion) <= 0);
    if (!selected.some((heading) => Number(heading[1]) === latestVersion.major
        && Number(heading[2]) === latestVersion.minor
        && Number(heading[3]) === latestVersion.patch)) {
        throw new Error(`${label} does not describe published ${latestVersion.tag}`);
    }

    const prefix = source.slice(0, headings[0]?.index ?? source.length).trimEnd();
    const sections = selected.map((heading) => {
        const index = headings.indexOf(heading);
        const end = headings[index + 1]?.index ?? source.length;
        return source.slice(heading.index, end).trim();
    });

    return `${prefix}\n\n${sections.join("\n\n")}\n`;
}

async function ensureReleaseNotes(silexRoot, repository, latestVersion) {
    try {
        await Promise.all([
            readFile(join(silexRoot, "CHANGELOG.fr.md")),
            readFile(join(silexRoot, "CHANGELOG.md")),
        ]);
        return;
    } catch {
        const fetch = spawnSync("git", ["-C", silexRoot, "fetch", "--quiet", "--depth=1", "origin", "refs/heads/main"]);
        if (fetch.status !== 0) throw new Error(`Unable to bootstrap release notes from ${repository}`);
    }

    for (const filename of ["CHANGELOG.fr.md", "CHANGELOG.md"]) {
        const result = spawnSync("git", ["-C", silexRoot, "show", `FETCH_HEAD:${filename}`], { encoding: "utf8" });
        if (result.status !== 0) throw new Error(`Unable to read ${filename} from ${repository} main`);
        await writeFile(
            join(silexRoot, filename),
            releasedChangelog(result.stdout, latestVersion, filename),
        );
    }
    console.log(`Bootstrapped release notes for ${latestVersion.tag} from Silex main`);
}

await rm(outputRoot, { recursive: true, force: true });
await mkdir(outputRoot, { recursive: true });

const silexRepository = "https://github.com/Matanek/Silex.git";
const silexVersion = latestVersionTag(silexRepository);
const documentationReference = process.env.SILEX_DOCUMENTATION_REF ?? `release/${silexVersion.major}.${silexVersion.minor}`;

const silexRoot = resolve(outputRoot, "Silex");
clone(silexRepository, silexRoot, silexVersion.tag);
await ensureReleaseNotes(silexRoot, silexRepository, silexVersion);
clone("https://github.com/Matanek/Silex-Documentation.git", resolve(outputRoot, "Silex-Documentation"), documentationReference);
clone("https://github.com/Matanek/Silex-Extension-VSCode.git", resolve(outputRoot, "Silex-Extension-VSCode"));
clone("https://github.com/Matanek/Silex-Registry.git", resolve(outputRoot, "Silex-Registry"));

const registryPackagesRoot = resolve(outputRoot, "Silex-Registry/registry/v1/packages");
const registrationEntries = await readdir(registryPackagesRoot, { withFileTypes: true });
let packageCount = 0;
for (const entry of registrationEntries.sort((left, right) => left.name.localeCompare(right.name, "en"))) {
    if (!entry.isFile() || !entry.name.endsWith(".json")) continue;

    const registrationPath = join(registryPackagesRoot, entry.name);
    const registration = JSON.parse(await readFile(registrationPath, "utf8"));
    const name = entry.name.slice(0, -5);
    if (
        registration.schema !== 1
        || !packagePattern.test(name)
        || registration.name !== name
        || typeof registration.repository !== "string"
        || !repositoryPattern.test(registration.repository)
    ) {
        throw new Error(`Registry entry '${entry.name}' has an invalid package contract`);
    }

    const destination = resolve(outputRoot, "Packages", name);
    clone(registration.repository, destination, null, true);
    const manifest = JSON.parse(await readFile(join(destination, "Package.json"), "utf8"));
    if (manifest.name !== name || normalizePackageDescription(manifest.description) === null) {
        throw new Error(`Package '${name}' has no valid manifest description`);
    }
    packageCount += 1;
}

console.log(`Fetched Silex ${silexVersion.tag}, documentation ${documentationReference}, the TextMate grammar, the registry, and ${packageCount} package manifests`);
