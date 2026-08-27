#!/usr/bin/env bash
#
# Bundle the SDK into a WordPress plugin.
#
#   bash bundle-into-plugin.sh /path/to/wp-content/plugins/metadata-viewer
#
# Copies src/ to <plugin>/lib/digi-tracker/ and prints the integration
# snippet. Deliberately does not touch the plugin's PHP -- wiring the SDK in
# is a decision about where in the boot order it belongs, and that is not
# something a copy script should guess.
#
# Why lib/ and a plain require rather than the plugin's Composer autoloader:
# metadata-viewer ships a generated vendor/ with no composer.json, so adding a
# PSR-4 namespace would mean hand-editing generated files that the next
# `composer dump-autoload` would silently discard.

set -euo pipefail

target="${1:-}"

if [ -z "$target" ]; then
    echo "usage: bash bundle-into-plugin.sh /path/to/wp-content/plugins/your-plugin" >&2
    exit 1
fi

if [ ! -d "$target" ]; then
    echo "No such plugin directory: $target" >&2
    exit 1
fi

src="$(cd "$(dirname "$0")/.." && pwd)/src"
dest="$target/lib/digi-tracker"

mkdir -p "$dest"
cp "$src/Client.php" "$src/Insights.php" "$dest/"

echo "Copied the SDK to $dest"
echo
echo "Now add this to the plugin's main file, after the ABSPATH guard:"
echo
cat <<'SNIPPET'
/**
 * Telemetry, opt-in only.
 *
 * Nothing is sent until the user clicks Allow on the admin notice. Never
 * call send_tracking_data( true ) and never pre-set the allow_tracking
 * option: wordpress.org Guideline 7 prohibits phoning home without consent,
 * and the notice is what makes this plugin compliant.
 */
function pluginizelab_metadata_viewer_telemetry() {
    if ( ! class_exists( '\PluginizeLab\DigiTracker\Client' ) ) {
        require_once __DIR__ . '/lib/digi-tracker/Client.php';
        require_once __DIR__ . '/lib/digi-tracker/Insights.php';
    }

    $client = new \PluginizeLab\DigiTracker\Client(
        'YOUR-PROJECT-HASH',
        'Metadata Viewer',
        __FILE__
    );

    $insights = $client->insights();

    // Names of other active plugins, so "what runs alongside us" is
    // answerable. Disclosed in the opt-in notice -- see data_we_collect().
    $insights->add_plugin_data();

    $insights->init();
}

pluginizelab_metadata_viewer_telemetry();
SNIPPET
echo
echo "Replace YOUR-PROJECT-HASH with the hash from the dashboard."
