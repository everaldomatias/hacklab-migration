#!/usr/bin/env bash
set -euo pipefail

# Gera um pacote ZIP do plugin com a pasta base (slug) no topo,
# excluindo dependências de desenvolvimento.

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
ROOT_DIR="$( cd "${SCRIPT_DIR}/.." && pwd )"
PARENT_DIR="$( dirname "$ROOT_DIR" )"
SLUG="$( basename "$ROOT_DIR" )"
OUTPUT_DIR="${ROOT_DIR}/zips"
OUTPUT_ZIP="${OUTPUT_DIR}/${SLUG}.zip"

mkdir -p "${OUTPUT_DIR}"

cd "${PARENT_DIR}"

rm -f "${OUTPUT_ZIP}"

zip -r "${OUTPUT_ZIP}" "${SLUG}" \
  -x "${SLUG}/node_modules/*" \
     "${SLUG}/zips/*" \
     "${SLUG}/dev-scripts/*" \
     "${SLUG}/.git/*" \
     "${SLUG}/.gitignore" \
     "${SLUG}/.editorconfig" \
     "${SLUG}/.DS_Store"

echo "ZIP gerado em: ${OUTPUT_ZIP}"
