import { chromium } from '/Users/riaz/Projects/node_modules/playwright/index.mjs';
import { fileURLToPath } from 'node:url';

const DIR = fileURLToPath( new URL( '.', import.meta.url ) ).replace( /\/$/, '' );
const PAGE = 'file://' + DIR + '/fixture-cards.html';

let pass = 0, fail = 0;
const chk = ( label, ok, extra = '' ) => {
	ok ? pass++ : fail++;
	console.log( ( ok ? 'PASS  ' : 'FAIL  ' ) + label + ( extra ? '  — ' + extra : '' ) );
};

const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: 1400, height: 1200 }, deviceScaleFactor: 2 } );
await page.goto( PAGE );

// Every card in a container, grouped into visual rows by their top edge, with
// each row's centre offset from the container's centre. Rows are what the fix
// is about, so the measurement is per row, not per card.
const rows = async container => page.evaluate( c => {
	const wrap = document.querySelector( c );
	const w = wrap.getBoundingClientRect();
	const byTop = new Map();

	for ( const el of wrap.children ) {
		const r = el.getBoundingClientRect();
		const key = Math.round( r.top );
		if ( ! byTop.has( key ) ) {
			byTop.set( key, [] );
		}
		byTop.get( key ).push( r );
	}

	return [ ...byTop.keys() ].sort( ( a, b ) => a - b ).map( k => {
		const cards = byTop.get( k );
		const left = Math.min( ...cards.map( r => r.left ) );
		const right = Math.max( ...cards.map( r => r.right ) );
		return {
			count: cards.length,
			offset: +( ( ( left + right ) / 2 ) - ( w.left + w.width / 2 ) ).toFixed( 1 ),
			widths: cards.map( r => Math.round( r.width ) ),
		};
	} );
}, container );

// Drives the container to an exact card count by cloning or removing, so one
// harness covers every count the product can produce.
const setCount = async ( container, n ) => page.evaluate( ( [ c, want ] ) => {
	const wrap = document.querySelector( c );
	while ( wrap.children.length > want ) {
		wrap.lastElementChild.remove();
	}
	while ( wrap.children.length < want ) {
		wrap.appendChild( wrap.firstElementChild.cloneNode( true ) );
	}
}, [ container, n ] );

const CENTRED = 1.5;

async function sweep( container, label, counts, expectRows ) {
	for ( const n of counts ) {
		await setCount( container, n );
		await page.waitForTimeout( 320 );
		const r = await rows( container );

		chk( `${ label }: ${ n } card(s) → ${ expectRows[ n ] } row(s)`, r.length === expectRows[ n ],
			'got ' + r.length + ' ' + JSON.stringify( r.map( x => x.count ) ) );

		const off = r.map( x => x.offset );
		chk( `${ label }: ${ n } card(s) every row centred`, off.every( o => Math.abs( o ) < CENTRED ),
			'offsets ' + JSON.stringify( off ) );

		// All cards one size, whatever the count — a short row must not stretch
		// its cards to fill the space the missing ones left.
		const all = r.flatMap( x => x.widths );
		chk( `${ label }: ${ n } card(s) all one width`, new Set( all ).size === 1,
			JSON.stringify( all ) );
	}
}

/* ── The card really is injected, so this is testing the shipped path ── */
chk( 'AI card injected into setup grid', await page.locator( '.shapeforms-setup-choices > *:last-child' ).evaluate( el => el.classList.contains( 'shapeforms-ai-card' ) ) );
chk( 'AI card injected into start grid', await page.locator( '.shapeforms-start-grid > *:last-child' ).evaluate( el => el.classList.contains( 'shapeforms-ai-card' ) ) );

/* ── The sparkle mark ──
 *
 * Both cards are in the DOM at once, so the gradient ids must differ: SVG
 * resolves `url(#id)` against the whole document, and a duplicate would make
 * the second card paint with the first card's gradient — which looks correct
 * here only because the two gradients happen to be identical today. Asserted
 * so it stays correct when one of them changes.
 */
const marks = await page.evaluate( () => {
	const ids = [ ...document.querySelectorAll( 'svg.shapeforms-ai-spark linearGradient' ) ].map( g => g.id );

	return {
		count: document.querySelectorAll( '.shapeforms-ai-card svg.shapeforms-ai-spark' ).length,
		leftoverDashicons: document.querySelectorAll( '.shapeforms-ai-card .dashicons:not(.shapeforms-start-card__arrow)' ).length,
		ids,
		unique: new Set( ids ).size,
		// Every fill must name a gradient that exists inside its own <svg>.
		resolves: [ ...document.querySelectorAll( 'svg.shapeforms-ai-spark path' ) ].every( path => {
			const ref = ( path.getAttribute( 'fill' ) || '' ).match( /^url\(#(.+)\)$/ );
			return !! ref && !! path.ownerSVGElement.querySelector( '#' + CSS.escape( ref[ 1 ] ) );
		} ),
		sizes: [ ...document.querySelectorAll( 'svg.shapeforms-ai-spark' ) ]
			.map( el => Math.round( el.getBoundingClientRect().width ) ),
		// The tile keeps the geometry of the ones beside it.
		tiles: [ '.shapeforms-setup-card__icon--blank', '.shapeforms-ai-card .shapeforms-setup-card__icon' ]
			.map( sel => Math.round( document.querySelector( sel ).getBoundingClientRect().width ) ),
	};
} );

chk( 'both cards carry the sparkle mark', 2 === marks.count, String( marks.count ) );
chk( 'no dashicon left on the AI cards', 0 === marks.leftoverDashicons, String( marks.leftoverDashicons ) );
chk( 'gradient ids are unique per card', 2 === marks.ids.length && 2 === marks.unique, JSON.stringify( marks.ids ) );
chk( 'every fill resolves to a gradient in its own svg', marks.resolves );
chk( 'marks render at the sizes the tiles expect', JSON.stringify( marks.sizes.slice().sort( ( a, b ) => a - b ) ) === '[26,34]', JSON.stringify( marks.sizes ) );
chk( 'AI tile matches its siblings', marks.tiles[ 0 ] === marks.tiles[ 1 ], JSON.stringify( marks.tiles ) );
chk( 'setup grid starts at 4 cards', ( await page.locator( '.shapeforms-setup-choices > *' ).count() ) === 4 );
chk( 'start grid starts at 3 cards', ( await page.locator( '.shapeforms-start-grid > *' ).count() ) === 3 );

console.log( '\n── 1400px: setup 3-up, start 2-up ──' );
await sweep( '.shapeforms-setup-choices', 'setup @1400', [ 1, 2, 3, 4, 5 ], { 1: 1, 2: 1, 3: 1, 4: 2, 5: 2 } );
await sweep( '.shapeforms-start-grid', 'start @1400', [ 1, 2, 3, 4 ], { 1: 1, 2: 1, 3: 2, 4: 2 } );

await setCount( '.shapeforms-setup-choices', 4 );
await setCount( '.shapeforms-start-grid', 3 );
await page.screenshot( { path: DIR + '/cards-1-wide.png', fullPage: true } );

// The count the user reported: two cards, one of them the AI card.
await setCount( '.shapeforms-setup-choices', 2 );
await page.waitForTimeout( 320 );
await page.locator( '.shapeforms-setup-choices' ).screenshot( { path: DIR + '/cards-2-two-up.png' } );

console.log( '\n── 900px: setup drops to 2-up ──' );
await page.setViewportSize( { width: 900, height: 1400 } );
await sweep( '.shapeforms-setup-choices', 'setup @900', [ 1, 2, 3, 4 ], { 1: 1, 2: 1, 3: 2, 4: 2 } );

console.log( '\n── 760px: start grid stacks ──' );
await page.setViewportSize( { width: 760, height: 1600 } );
await sweep( '.shapeforms-start-grid', 'start @760', [ 1, 2, 3 ], { 1: 1, 2: 2, 3: 3 } );

console.log( '\n── 440px: everything stacks ──' );
await page.setViewportSize( { width: 440, height: 2200 } );
await sweep( '.shapeforms-setup-choices', 'setup @440', [ 1, 3, 4 ], { 1: 1, 3: 3, 4: 4 } );

/* ── Equal heights within a row: grid gave this for free, flex must too ── */
await page.setViewportSize( { width: 1400, height: 1200 } );
await setCount( '.shapeforms-setup-choices', 3 );
await page.waitForTimeout( 320 );
const heights = await page.evaluate( () => [ ...document.querySelectorAll( '.shapeforms-setup-choices > *' ) ]
	.map( el => Math.round( el.getBoundingClientRect().height ) ) );
chk( 'cards in a row are equal height', new Set( heights ).size === 1, JSON.stringify( heights ) );

/* ── No sideways overflow at any width ── */
for ( const w of [ 1400, 900, 760, 600, 440, 360 ] ) {
	await page.setViewportSize( { width: w, height: 1800 } );
	await page.waitForTimeout( 320 );
	const over = await page.evaluate( () => document.documentElement.scrollWidth > document.documentElement.clientWidth );
	chk( `no horizontal overflow at ${ w }px`, ! over );
}

await browser.close();
console.log( '\n' + pass + ' passed, ' + fail + ' failed' );
process.exit( fail ? 1 : 0 );
