/**
 * Wraps the captured settings markup in the real stylesheets and the real
 * inline behaviour, producing fixture-settings.html.
 *
 * The markup is dumped from WordPress itself (see settings-ai.mjs for how to
 * refresh it) rather than hand-written, because the bug this suite exists to
 * catch — a row carrying `hidden` that CSS keeps on screen anyway — only
 * appears when the shipped markup and the shipped CSS meet.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const DIR = fileURLToPath( new URL( '.', import.meta.url ) ).replace( /\/$/, '' );
const LITE = DIR.replace( /\/tests\/browser$/, '' );
const WP = '/Users/riaz/Local Sites/develop/app/public';

const body = readFileSync( DIR + '/.settings-ai.html', 'utf8' );

// The provider-switch behaviour is added to the page with wp_add_inline_script,
// so it is lifted straight out of the PHP that emits it — no second copy to
// drift from the original.
const admin = readFileSync( LITE + '/admin/class-shapeforms-lite-admin.php', 'utf8' );
const start = admin.indexOf( 'var aiProvider=document.querySelector("[data-bf-ai-provider]");' );
const end = admin.indexOf( "\t\t\t\t\t})();'", start );
if ( start === -1 || end === -1 ) {
	throw new Error( 'Could not find the AI provider-switch script in the admin class — did it move or get renamed?' );
}
const inline = admin.slice( start, end );

writeFileSync( DIR + '/fixture-settings.html', `<!doctype html>
<html><head><meta charset="utf-8">
<link rel="stylesheet" href="${ WP }/wp-includes/css/buttons.min.css">
<link rel="stylesheet" href="${ WP }/wp-includes/css/dashicons.min.css">
<link rel="stylesheet" href="${ WP }/wp-admin/css/common.min.css">
<link rel="stylesheet" href="${ WP }/wp-admin/css/forms.min.css">
<link rel="stylesheet" href="${ LITE }/assets/css/settings.css">
<style>body{margin:0;background:#f0f0f1;font-family:-apple-system,"Segoe UI",Roboto,sans-serif}</style>
</head><body>
${ body }
<script src="${ LITE }/assets/js/admin-select.js"></script>
<script>(function(){${ inline }})();</script>
</body></html>` );

console.log( 'fixture-settings.html built' );
