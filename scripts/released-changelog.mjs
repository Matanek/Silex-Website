// Editorial corrections may advance independently of immutable compiler tags.
// Only entries backed by a published stable tag can enter the website snapshot.
export function releasedChangelog(source, publishedVersions, latestVersion, label) {
    const headings = [...source.matchAll(/^## (.+)[ \t]*$/gm)];
    const selected = headings.flatMap((heading, index) => {
        const version = heading[1].match(/^\[(\d+\.\d+\.\d+)\] - \d{4}-\d{2}-\d{2}[ \t]*$/)?.[1];
        if (!version || !publishedVersions.has(version)) return [];
        return [{ version, text: source.slice(heading.index, headings[index + 1]?.index ?? source.length).trim() }];
    });
    if (!selected.some(({ version }) => version === latestVersion)) {
        throw new Error(`${label} does not describe published v${latestVersion}`);
    }
    const prefix = source.slice(0, headings[0]?.index ?? 0).trimEnd();
    return `${prefix}\n\n${selected.map(({ text }) => text).join("\n\n")}\n`;
}
