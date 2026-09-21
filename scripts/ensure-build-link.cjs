const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const link = path.join(root, 'build');
const target = path.join(root, 'public', 'build');
let entry;
try { entry = fs.lstatSync(link); } catch (error) { if (error.code !== 'ENOENT') throw error; }
if (entry?.isSymbolicLink()) {
    if (path.resolve(root, fs.readlinkSync(link)) !== target) throw new Error('build points outside public/build');
} else {
    if (entry) {
        // Git on Windows may materialize a symlink as this exact text file.
        if (!entry.isFile() || fs.readFileSync(link, 'utf8').trim() !== 'public/build') {
            throw new Error('Refusing to replace unexpected build file or directory');
        }
        fs.unlinkSync(link);
    }
    fs.symlinkSync(process.platform === 'win32' ? target : 'public/build', link,
        process.platform === 'win32' ? 'junction' : 'dir');
}
