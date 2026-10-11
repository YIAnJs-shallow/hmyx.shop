"""Check literal local navigation targets used by project pages."""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PATTERNS = [
    re.compile(r'''\b(?:href|action)\s*=\s*["']([^"']+)["']''', re.I),
    re.compile(r'''\b(?:location\.href|fetch)\s*\(\s*["']([^"']+)["']''', re.I),
]
SKIP_PREFIXES = ('#', 'javascript:', 'mailto:', 'tel:', 'http:', 'https:', '//')
errors = []

for path in list(ROOT.glob('*')) + list((ROOT / 'admin').rglob('*.php')):
    if path.suffix.lower() not in {'.php', '.html', '.js'} or not path.is_file():
        continue
    text = path.read_text(encoding='utf-8')
    for pattern in PATTERNS:
        for target in pattern.findall(text):
            target = target.split('?', 1)[0]
            if not target or target.startswith(SKIP_PREFIXES) or '$' in target or '{' in target:
                continue
            if not re.fullmatch(r'[A-Za-z0-9_./-]+\.(?:php|html|js)', target):
                continue
            if not ((ROOT if target.startswith('/') else (ROOT / 'admin' if path.parent.name == 'inc' else path.parent)) / target.lstrip('/')).is_file():
                errors.append(f'{path.relative_to(ROOT)}: missing local target {target}')

if errors:
    raise SystemExit('\n'.join(errors))
print('Local navigation target check passed.')
