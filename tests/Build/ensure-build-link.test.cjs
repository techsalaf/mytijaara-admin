const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

for (const initial of ['missing', 'placeholder', 'unexpected']) {
    test(`build link: ${initial}`, () => {
        const root = fs.mkdtempSync(path.join(os.tmpdir(), 'build-link-test-'));
        try {
            fs.mkdirSync(path.join(root, 'scripts'));
            fs.mkdirSync(path.join(root, 'public', 'build'), { recursive: true });
            fs.copyFileSync(path.resolve(__dirname, '../../scripts/ensure-build-link.cjs'), path.join(root, 'scripts', 'ensure-build-link.cjs'));
            const link = path.join(root, 'build');
            if (initial !== 'missing') fs.writeFileSync(link, initial === 'placeholder' ? 'public/build\n' : 'preserve me');
            const run = () => spawnSync(process.execPath, [path.join(root, 'scripts', 'ensure-build-link.cjs')]);
            if (initial === 'unexpected') {
                assert.notEqual(run().status, 0);
                assert.equal(fs.readFileSync(link, 'utf8'), 'preserve me');
            } else {
                assert.equal(run().status, 0);
                assert.equal(fs.realpathSync(link), fs.realpathSync(path.join(root, 'public', 'build')));
                assert.equal(run().status, 0, 'repeat build must be harmless');
            }
        } finally {
            fs.rmSync(root, { recursive: true, force: true });
        }
    });
}
