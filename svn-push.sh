#!/usr/bin/env bash
#
# svn-push.sh — publish llms-txt-for-woocommerce to the WordPress.org plugin SVN.
#
# WP.org SVN is a RELEASE system, not git: only commit ready-to-ship code.
# Layout published:
#   trunk/        <- current plugin code (the install zip)
#   tags/<VER>/   <- immutable snapshot of this release (what installers actually pull)
#   assets/       <- wp.org LISTING images (screenshots/banner/icon) — NOT bundled in installs
#
# Trunk/tag source of truth = this plugin working tree (NOT the stale ~/Downloads zip,
# which predates the "Agentic Commerce" rename).
#
# Usage:
#   ./svn-push.sh            # interactive: SVN prompts for the password, caches it
#   SVN_PASSWORD=xxx ./svn-push.sh   # non-interactive
#
# Your SVN username is your WordPress.org username (case-sensitive). SVN password is
# SEPARATE from your wp.org login — set/get it at:
#   https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password
set -euo pipefail

SLUG="agentic-commerce-llms-txt"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
SVN_USER="${SVN_USER:-xpaysh}"
VER="1.0.0"

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"   # this plugin dir = trunk source
SHOTS="${HOME}/Downloads/llms-txt-svn-assets"          # real 1920x1080 screenshots
WC="${HOME}/Downloads/${SLUG}-svn"                     # local svn working copy

AUTH=(--username "${SVN_USER}" --non-interactive)
if [[ -n "${SVN_PASSWORD:-}" ]]; then
  AUTH+=(--password "${SVN_PASSWORD}")
else
  AUTH=(--username "${SVN_USER}")   # let svn prompt + cache interactively
fi

echo "==> Verifying repo is provisioned..."
if ! svn info "${SVN_URL}" >/dev/null 2>&1; then
  echo "ERROR: ${SVN_URL} does not exist yet." >&2
  echo "WP.org grants commit access within ~1h of the approval email. Re-run later." >&2
  exit 1
fi

echo "==> Checking out working copy to ${WC}"
if [[ -d "${WC}/.svn" ]]; then
  svn update "${WC}"
else
  svn checkout "${SVN_URL}" "${WC}"
fi
cd "${WC}"

mkdir -p trunk assets "tags"

echo "==> Syncing plugin code into trunk/"
rsync -a --delete \
  --exclude '.git' --exclude '.wordpress-org' --exclude '.distignore' \
  --exclude 'README.md' --exclude '.github' --exclude 'svn-push.sh' \
  --exclude '.svn' \
  "${SRC}/" trunk/

echo "==> Staging listing screenshots into assets/"
for n in 1 2 3 4; do
  cp "${SHOTS}/screenshot-${n}.png" "assets/screenshot-${n}.png"
done
# NOTE: banner-*.png / icon-*.png are still 1x1 placeholders — intentionally NOT uploaded.
# Add real banner+icon to ${SHOTS} and extend this loop when designed.

echo "==> svn add (new files only)"
svn add --force trunk assets >/dev/null 2>&1 || true
# set mime-type on images so wp.org serves them correctly
svn propset svn:mime-type image/png assets/*.png >/dev/null 2>&1 || true

echo "==> Committing trunk + assets"
svn commit "${AUTH[@]}" -m "Release ${VER}: commerce-aware /llms.txt + /llms-full.txt for WooCommerce; listing screenshots"

echo "==> Tagging ${VER}"
svn update
svn copy trunk "tags/${VER}"
svn commit "${AUTH[@]}" -m "Tag ${VER}"

echo "==> DONE. Listing live at https://wordpress.org/plugins/${SLUG}/ (assets may take minutes to render)."
