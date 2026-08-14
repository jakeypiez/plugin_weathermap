#!/usr/bin/env bash
#
# Cacti Weathermap - macOS Metadata & AppleDouble Cleanup Script
# Removes .DS_Store, ._* (AppleDouble), __MACOSX directories, and extended attributes.
#
# Usage:
#   ./clean-mac-files.sh [/path/to/cacti/plugins/weathermap]
#

set -euo pipefail

TARGET_DIR="${1:-$(cd "$(dirname "$0")/.." && pwd)}"

if [ ! -d "$TARGET_DIR" ]; then
    echo "Error: Directory '$TARGET_DIR' does not exist." >&2
    exit 1
fi

echo "=== Cleaning macOS metadata in: $TARGET_DIR ==="

COUNT=0

# 1. Remove AppleDouble files (._*)
echo "-> Scanning for AppleDouble files (._*)..."
while IFS= read -r -d '' file; do
    echo "   Removing: $file"
    rm -f "$file"
    COUNT=$((COUNT + 1))
done < <(find "$TARGET_DIR" -type f -name "._*" -print0)

# 2. Remove .DS_Store files
echo "-> Scanning for .DS_Store files..."
while IFS= read -r -d '' file; do
    echo "   Removing: $file"
    rm -f "$file"
    COUNT=$((COUNT + 1))
done < <(find "$TARGET_DIR" -type f -name ".DS_Store*" -print0)

# 3. Remove __MACOSX directories
echo "-> Scanning for __MACOSX directories..."
while IFS= read -r -d '' dir; do
    echo "   Removing directory: $dir"
    rm -rf "$dir"
    COUNT=$((COUNT + 1))
done < <(find "$TARGET_DIR" -type d -name "__MACOSX" -print0)

# 4. Remove macOS special folders (.Spotlight-V100, .Trashes, .TemporaryItems)
echo "-> Scanning for macOS volume metadata folders..."
while IFS= read -r -d '' item; do
    echo "   Removing: $item"
    rm -rf "$item"
    COUNT=$((COUNT + 1))
done < <(find "$TARGET_DIR" \( -name ".Spotlight-V100" -o -name ".Trashes" -o -name ".TemporaryItems" \) -print0)

# 5. Clear extended attributes if xattr command is available
if command -v xattr >/dev/null 2>&1; then
    echo "-> Clearing filesystem extended attributes (xattr)..."
    xattr -cr "$TARGET_DIR" 2>/dev/null || true
fi

echo "=========================================="
echo "Cleanup complete! Removed $COUNT artifact(s)."
echo "=========================================="
