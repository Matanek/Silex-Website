// Editorial corrections may advance independently of immutable compiler tags.
// Only entries backed by a published stable tag can enter the website snapshot.
export function releasedChangelog(source, publishedVersions, latestVersion, label, baseline = null) {
    const headings = [...source.matchAll(/^## (.+)[ \t]*$/gm)];
    const selected = headings.flatMap((heading, index) => {
        const version = heading[1].match(/^\[(\d+\.\d+\.\d+)\] - \d{4}-\d{2}-\d{2}[ \t]*$/)?.[1];
        if (!version || !publishedVersions.has(version)) return [];
        return [{ version, text: source.slice(heading.index, headings[index + 1]?.index ?? source.length).trim() }];
    });
    if (!selected.some(({ version }) => version === latestVersion)) {
        throw new Error(`${label} does not describe published v${latestVersion}`);
    }
    if (baseline !== null) {
        for (const heading of baseline.matchAll(/^## \[(\d+\.\d+\.\d+)\] - (\d{4}-\d{2}-\d{2})[ \t]*$/gm)) {
            if (!publishedVersions.has(heading[1])) continue;
            const current = selected.find(({ version }) => version === heading[1]);
            if (!current || !current.text.startsWith(`## [${heading[1]}] - ${heading[2]}`)) {
                throw new Error(`${label} removes or redates published v${heading[1]}`);
            }
        }
    }
    const prefix = source.slice(0, headings[0]?.index ?? 0).trimEnd();
    return `${prefix}\n\n${selected.map(({ text }) => text).join("\n\n")}\n`;
}
