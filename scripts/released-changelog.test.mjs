import { test } from "node:test";
import assert from "node:assert/strict";
import { releasedChangelog } from "./released-changelog.mjs";

test("published notes accept editorial corrections without admitting pending versions", () => {
    const source = `# Notes\n\n## [Unreleased]\nsecret pending change\n\n## [0.48.0] - 2026-09-25\nfuture candidate\n\n## [0.47.0] - 2026-09-24\ncorrected common backend scope\n\n## [0.46.2] - 2026-09-20\nunpublished historical candidate\n\n## [0.46.1] - 2026-09-19\npublished history\n`;
    const result = releasedChangelog(source, new Set(["0.47.0", "0.46.1"]), "0.47.0", "FR");
    assert.match(result, /corrected common backend scope/);
    assert.match(result, /published history/);
    assert.doesNotMatch(result, /Unreleased|pending|candidate|0\.48\.0|0\.46\.2/);
});

test("a missing latest published entry blocks the snapshot", () => {
    assert.throws(() => releasedChangelog("# Notes\n## [Unreleased]\nnew work", new Set(["0.47.0"]), "0.47.0", "FR"), /does not describe published/);
});

test("a pending section after a published entry cannot leak into its body", () => {
    const result = releasedChangelog("# Notes\n## [0.47.0] - 2026-09-24\nreleased\n## [Unreleased]\nprivate draft", new Set(["0.47.0"]), "0.47.0", "EN");
    assert.doesNotMatch(result, /private draft/);
});

test("editorial corrections cannot silently delete or redate published history", () => {
    const baseline = "# Notes\n## [0.47.0] - 2026-09-24\nold description\n## [0.46.1] - 2026-09-19\nolder history\n";
    const published = new Set(["0.47.0", "0.46.1"]);
    assert.match(releasedChangelog(baseline.replace("old description", "corrected description"), published, "0.47.0", "FR", baseline), /corrected description/);
    assert.throws(() => releasedChangelog(baseline.split("## [0.46.1]")[0], published, "0.47.0", "FR", baseline), /removes or redates/);
    assert.throws(() => releasedChangelog(baseline.replace("2026-09-19", "2026-09-20"), published, "0.47.0", "FR", baseline), /removes or redates/);
});
