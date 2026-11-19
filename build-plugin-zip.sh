#!/usr/bin/env sh
set -e

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
cd "$ROOT_DIR"

PLUGIN_DIR="site-mode-manager"
ZIP_NAME="site-mode-manager.zip"

if [ ! -d "$PLUGIN_DIR" ]; then
  echo "No se encontró la carpeta '$PLUGIN_DIR'." >&2
  exit 1
fi

rm -f "$ZIP_NAME"

if command -v zip >/dev/null 2>&1; then
  zip -r "$ZIP_NAME" "$PLUGIN_DIR" -x "*.DS_Store" -x "*/.git/*"
elif command -v python3 >/dev/null 2>&1; then
  python3 - "$PLUGIN_DIR" "$ZIP_NAME" <<'PY'
import os, sys, zipfile
src, out = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(src):
        dirs[:] = [d for d in dirs if d != '.git']
        for f in files:
            if f == '.DS_Store':
                continue
            p = os.path.join(root, f)
            z.write(p, p)
PY
elif command -v powershell.exe >/dev/null 2>&1; then
  powershell.exe -NoProfile -Command "Compress-Archive -Path '$PLUGIN_DIR' -DestinationPath '$ZIP_NAME' -Force"
else
  echo "No se encontró 'zip', 'python3' ni 'powershell.exe' para crear el archivo." >&2
  exit 1
fi

echo "Creado: $ZIP_NAME"
