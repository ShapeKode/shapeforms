/**
 * The AI settings tab, driven in a browser against the real rendered markup and
 * the real stylesheet.
 *
 * Exists because of a bug that no PHP test could see: every provider's API key
 * field carried the `hidden` attribute and rendered anyway, because
 * `.shapeforms-field-row { display: flex }` beats the user-agent
 * `[hidden] { display: none }` rule. The markup was right, the CSS was right in
 * isolation, and the page was wrong.
 */
import { chromium } from '/Users/riaz/Projects/node_modules/playwright/index.mjs';
import { fileURLToPath } from 'node:url';

const DIR = fileURLToPath( new URL( '.', import.meta.url ) ).replace( /\/$/, '' );
const PAGE = 'file://' + DIR + '/fixture-settings.html';

let pass = 0, fail = 0;
const chk = ( label, ok, extra = '' ) => {
	ok ? pass++ : fail++;
	console.log( ( ok ? 'PASS  ' : 'FAIL  ' ) + label + ( extra ? '  — ' + extra : '' ) );
};

const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: 1280, height: 1000 }, deviceScaleFactor: 2 } );
await page.goto( PAGE );
await page.waitForTimeout( 200 );

const visibleKeys = () => page.$$eval( '[data-bf-ai-key-for]', els =>
	els.filter( e => e.offsetParent !== null ).map( e => e.getAttribute( 'data-bf-ai-key-for' ) ) );
const visibleModels = () => page.$$eval( '[data-bf-ai-model-for]', els =>
	els.filter( e => e.offsetParent !== null ).map( e => e.getAttribute( 'data-bf-ai-model-for' ) ) );

const selected = () => page.$eval( '[data-bf-ai-provider]', s => s.value );

/* ── The bug: all four key fields on screen at once ── */
chk( 'four key fields exist in the markup', ( await page.$$( '[data-bf-ai-key-for]' ) ).length === 4 );
let vis = await visibleKeys();
chk( 'exactly ONE key field is visible', vis.length === 1, JSON.stringify( vis ) );
chk( 'the visible one belongs to the selected provider', vis[ 0 ] === ( await selected() ), vis[ 0 ] + ' vs ' + ( await selected() ) );

/* ── Switching provider swaps the field, for every provider ── */
for ( const slug of [ 'anthropic', 'openai', 'gemini', 'openrouter' ] ) {
	await page.selectOption( '[data-bf-ai-provider]', slug );
	await page.waitForTimeout( 120 );
	const k = await visibleKeys();
	chk( `${ slug }: exactly one key field visible`, k.length === 1 && k[ 0 ] === slug, JSON.stringify( k ) );

	// The model picker only exists for providers that publish a catalogue, so
	// "none visible" is correct for the direct providers rather than a failure.
	const m = await visibleModels();
	const expected = slug === 'openrouter' ? 1 : 0;
	chk( `${ slug }: ${ expected } model picker visible`, m.length === expected, JSON.stringify( m ) );
}

/* ── A hidden row must occupy no space, not merely be transparent ── */
await page.selectOption( '[data-bf-ai-provider]', 'openai' );
await page.waitForTimeout( 120 );
const boxes = await page.$$eval( '[data-bf-ai-key-for]', els => els.map( e => ( {
	slug: e.getAttribute( 'data-bf-ai-key-for' ),
	h: Math.round( e.getBoundingClientRect().height ),
	display: getComputedStyle( e ).display,
} ) ) );
chk( 'hidden rows compute to display:none', boxes.filter( b => b.slug !== 'openai' ).every( b => b.display === 'none' ), JSON.stringify( boxes ) );
chk( 'hidden rows take up no height', boxes.filter( b => b.slug !== 'openai' ).every( b => b.h === 0 ) );
chk( 'the visible row has real height', boxes.find( b => b.slug === 'openai' ).h > 20 );

/* ── Only the visible field should be reachable by keyboard ── */
const focusable = await page.$$eval( 'input[name^="shapeforms_ai_api_key"]', els =>
	els.filter( e => e.offsetParent !== null ).length );
chk( 'only one key input is focusable', focusable === 1, focusable + ' focusable' );

/* ── Every field still posts, so switching cannot wipe another provider's key ── */
const inputs = await page.$$eval( 'input[name^="shapeforms_ai_api_key"]', els => els.length );
chk( 'all four key inputs remain in the form', inputs === 4, inputs + ' inputs' );
chk( 'no key value is present in the DOM', await page.$$eval( 'input[name^="shapeforms_ai_api_key"]', els => els.every( e => e.value === '' ) ) );

await page.screenshot( { path: DIR + '/settings-ai.png', fullPage: false } );
await browser.close();
console.log( '\n' + pass + ' passed, ' + fail + ' failed' );
process.exit( fail ? 1 : 0 );
