#!/usr/bin/env bash
# Bootstrap the OMR scanner's Python environment.
# Idempotent — safe to re-run after a pull or after adding new dependencies.
#
# WHY a project-local venv:
#   The scanner needs cv2 / numpy / pdf2image, which aren't in the system Python.
#   Installing them under ~/.local works for the dev shell but Apache (running
#   as www-data) can't traverse most home directories. A venv inside the web
#   tree, made world-readable, sidesteps that.
#
# Usage (from any cwd):
#   bash /srv/www/phdportal/scripts/install_omr.sh
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VENV="$APP_ROOT/.venv-omr"

echo ">> Project root: $APP_ROOT"

# ---- 1. System packages the scanner shells out to ---------------------------
# pdftoppm rasterizes PDF pages; libgl1 is required by opencv-python-headless
# at runtime even on headless boxes (libGL.so.1 dlopen). Install only if missing.
need_apt=()
command -v pdftoppm >/dev/null 2>&1 || need_apt+=(poppler-utils)
ldconfig -p | grep -q libGL.so.1   || need_apt+=(libgl1)
# Test capability rather than package name — python3-venv vs python3.12-venv
# differs across distros and meta-packages aren't always installed.
python3 -m venv --help >/dev/null 2>&1 || need_apt+=(python3-venv)

if [ "${#need_apt[@]}" -gt 0 ]; then
    echo ">> Installing missing apt packages: ${need_apt[*]}"
    sudo apt-get update
    sudo apt-get install -y "${need_apt[@]}"
else
    echo ">> All required apt packages are present."
fi

# ---- 2. Create / refresh the venv ------------------------------------------
if [ ! -x "$VENV/bin/python" ]; then
    echo ">> Creating venv at $VENV"
    python3 -m venv "$VENV"
fi

echo ">> Upgrading pip and installing scanner deps"
"$VENV/bin/pip" install --quiet --upgrade pip
"$VENV/bin/pip" install --quiet opencv-python-headless pdf2image numpy

# ---- 3. Make the venv readable by Apache (www-data) ------------------------
# Owner stays as the developer; we only widen "other" to r-x on dirs and r on
# files. www-data can then exec the python binary and load the .so files.
echo ">> Setting o+rX on $VENV"
chmod -R o+rX "$VENV"

# ---- 4. Smoke-test the same way PHP will invoke it -------------------------
echo ">> Smoke test:"
"$VENV/bin/python" - <<'PY'
import cv2, numpy
print(f"   cv2 {cv2.__version__}, numpy {numpy.__version__}")
PY

echo ">> Done. config.php's OMR_PYTHON_BIN will auto-pick up $VENV/bin/python."
