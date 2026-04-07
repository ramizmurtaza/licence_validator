#!/bin/bash
# ─────────────────────────────────────────────────────────────────
#  Ramiz License Client — IonCube Encoder Build Script
#  Run this on your build server to produce the encoded dist/
#  Requires: IonCube Encoder (https://www.ioncube.com/encoder.php)
#
#  Usage:
#    chmod +x build/ioncube/encode.sh
#    ./build/ioncube/encode.sh
# ─────────────────────────────────────────────────────────────────

set -e

IONCUBE_ENCODER="/usr/local/ioncube/ioncube_encoder.php8"
SRC_DIR="../../src"
DIST_DIR="../../dist/src"
LICENSE_FILE="./ramiz_license.ilic"

if [ ! -f "$IONCUBE_ENCODER" ]; then
    echo "ERROR: IonCube encoder not found at $IONCUBE_ENCODER"
    echo "Download from https://www.ioncube.com/encoder.php"
    exit 1
fi

echo "Cleaning dist..."
rm -rf "$DIST_DIR"
mkdir -p "$DIST_DIR"

echo "Encoding PHP source files..."
"$IONCUBE_ENCODER" \
    --php-version 8.1 \
    --without-loader-check \
    --optimise \
    --obfuscate all \
    --encrypt-key "RAMIZ_IONCUBE_KEY_$(date +%Y)" \
    --license-file "$LICENSE_FILE" \
    --with-license "$LICENSE_FILE" \
    --no-doc-comments \
    --ignore "*.md" \
    --ignore "*.txt" \
    "$SRC_DIR" \
    "$DIST_DIR"

echo ""
echo "Done. Encoded files are in: dist/src/"
echo "Replace the src/ directory in the published package with dist/src/"
