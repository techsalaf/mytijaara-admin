from pathlib import Path
import subprocess
root = Path('docs/remediation-evidence')
for name, flag in [('final-diff-stat.txt', '--stat'), ('final-diff-names.txt', '--name-status')]:
    # Exclude these two self-describing artifacts from the stat to avoid a recursive count.
    content = subprocess.check_output(['git', 'diff', flag, 'b87aa242', '--', '.',
        ':!docs/remediation-evidence/final-diff-stat.txt', ':!docs/remediation-evidence/final-diff-names.txt'])
    (root/name).write_bytes(b'Comparison: b87aa242 to final reviewed tree. Excludes these two inventory files themselves.\n'+content)
