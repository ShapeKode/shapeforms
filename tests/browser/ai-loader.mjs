import { chromium } from '/Users/riaz/Projects/node_modules/playwright/index.mjs';
import { fileURLToPath } from 'node:url';

const DIR = fileURLToPath( new URL( '.', import.meta.url ) ).replace( /\/$/, '' );
const PAGE = 'file://' + DIR + '/fixture-loader.html';

let pass = 0, fail = 0;
const chk = ( label, ok, extra = '' ) => {
	ok ? pass++ : fail++;
	console.log( ( ok ? 'PASS  ' : 'FAIL  ' ) + label + ( extra ? '  — ' + extra : '' ) );
};

const browser = await chromium.launch();

async function open( reducedMotion ) {
	const page = await browser.newPage( {
		viewport: { width: 900, height: 900 },
		deviceScaleFactor: 2,
		reducedMotion: reducedMotion ? 'reduce' : 'no-preference',
	} );
	await page.goto( PAGE );
	await page.click( '.shapeforms-ai-card' );
	await page.fill( '.shapeforms-ai-modal__input', 'A customer feedback survey with a rating' );
	return page;
}

/* ── Idle: the preview must not be visible before anything is asked for ── */
const idle = await open( false );
chk( 'skeleton hidden before submit', await idle.locator( '.shapeforms-ai-modal__skeleton' ).isHidden() );
chk( 'chips visible before submit', await idle.locator( '.shapeforms-ai-modal__suggestions' ).isVisible() );
await idle.locator( '.shapeforms-ai-modal__panel' ).screenshot( { path: DIR + '/loader-1-idle.png' } );

/* ── Busy ── */
const busy = await open( false );
await busy.click( '.shapeforms-ai-modal__submit' );
await busy.waitForTimeout( 700 );

chk( 'skeleton visible while busy', await busy.locator( '.shapeforms-ai-modal__skeleton' ).isVisible() );
chk( 'chips hidden while busy', await busy.locator( '.shapeforms-ai-modal__suggestions' ).isHidden() );
chk( 'suggest label hidden while busy', await busy.locator( '.shapeforms-ai-modal__suggest-label' ).isHidden() );
chk( 'notice stays hidden while busy', await busy.locator( '.shapeforms-ai-modal__notice' ).isHidden() );
chk( 'status text announced', ( await busy.locator( '.shapeforms-ai-skeleton__status-text' ).textContent() ) === 'Building your form…' );
chk( 'submit disabled', await busy.locator( '.shapeforms-ai-modal__submit' ).isDisabled() );
chk( 'submit label unchanged', ( await busy.locator( '.shapeforms-ai-modal__submit' ).textContent() ) === 'Generate' );
chk( 'cancel disabled', await busy.locator( '.shapeforms-ai-modal__cancel' ).isDisabled() );
chk( 'preview rows rendered', ( await busy.locator( '.shapeforms-ai-skeleton__row' ).count() ) === 2 );
chk( 'preview bars rendered', ( await busy.locator( '.shapeforms-ai-skeleton__bar' ).count() ) === 6 );
chk( 'first row is two columns', ( await busy.locator( '.shapeforms-ai-skeleton__row' ).nth( 0 ).locator( '.shapeforms-ai-skeleton__field' ).count() ) === 2 );
chk( 'preview is aria-hidden', ( await busy.locator( '.shapeforms-ai-skeleton__form' ).getAttribute( 'aria-hidden' ) ) === 'true' );
chk( 'status is a live region', ( await busy.locator( '.shapeforms-ai-skeleton__status' ).getAttribute( 'role' ) ) === 'status' );

// The bars must actually be laid out — a zero-height or zero-width bar animates
// invisibly and the whole thing reads as a blank gap.
const box = await busy.locator( '.shapeforms-ai-skeleton__bar--control' ).first().boundingBox();
chk( 'control bar has real size', box.height > 25 && box.width > 200, JSON.stringify( box ) );

const labelBox = await busy.locator( '.shapeforms-ai-skeleton__bar--label' ).first().boundingBox();
chk( 'label bar is narrower than its control', labelBox.width < box.width * 0.5, labelBox.width + ' vs ' + box.width );

// The sweep must MOVE. A frozen gradient looks like a rendering bug, and this
// is the one thing a static screenshot cannot show.
const pos = async () => busy.locator( '.shapeforms-ai-skeleton__bar' ).first().evaluate(
	el => getComputedStyle( el ).backgroundPosition
);
const a = await pos();
await busy.waitForTimeout( 350 );
const b = await pos();
chk( 'shimmer is animating', a !== b, a + ' -> ' + b );

// Two rows should not sweep in lockstep.
const rowPos = async n => busy.locator( '.shapeforms-ai-skeleton__row' ).nth( n ).locator( '.shapeforms-ai-skeleton__bar' ).first()
	.evaluate( el => getComputedStyle( el ).backgroundPosition );
chk( 'rows are offset from each other', ( await rowPos( 0 ) ) !== ( await rowPos( 1 ) ) );

await busy.locator( '.shapeforms-ai-modal__panel' ).screenshot( { path: DIR + '/loader-2-busy.png' } );

// Mid-sweep frame, so the screenshot catches the band somewhere visible.
await busy.waitForTimeout( 400 );
await busy.locator( '.shapeforms-ai-modal__panel' ).screenshot( { path: DIR + '/loader-3-busy-later.png' } );

/* ── Panel growth: the preview replaces the chips, so the jump must be small ── */
const idleH = ( await idle.locator( '.shapeforms-ai-modal__panel' ).boundingBox() ).height;
const busyH = ( await busy.locator( '.shapeforms-ai-modal__panel' ).boundingBox() ).height;
chk( 'panel height jump under 100px', Math.abs( busyH - idleH ) < 100, idleH + ' -> ' + busyH );

/* ── Reduced motion ── */
const calm = await open( true );
await calm.click( '.shapeforms-ai-modal__submit' );
await calm.waitForTimeout( 500 );
const calmStyle = await calm.locator( '.shapeforms-ai-skeleton__bar' ).first().evaluate( el => {
	const s = getComputedStyle( el );
	return { name: s.animationName, image: s.backgroundImage, opacity: s.opacity };
} );
chk( 'no shimmer animation under reduced motion', calmStyle.name === 'none', calmStyle.name );
chk( 'no frozen highlight under reduced motion', calmStyle.image === 'none', calmStyle.image );
const rowOpacity = await calm.locator( '.shapeforms-ai-skeleton__row' ).nth( 1 ).evaluate( el => getComputedStyle( el ).opacity );
chk( 'rows stay visible under reduced motion', rowOpacity === '1', rowOpacity );
chk( 'status still readable under reduced motion', await calm.locator( '.shapeforms-ai-skeleton__status-text' ).isVisible() );
await calm.locator( '.shapeforms-ai-modal__panel' ).screenshot( { path: DIR + '/loader-4-reduced.png' } );

/* ── Recovery: a failure must clear the preview and show the error ── */
const err = await browser.newPage( { viewport: { width: 900, height: 900 }, deviceScaleFactor: 2 } );
await err.goto( PAGE );
await err.evaluate( () => {
	jQuery.ajax = function () {
		const chain = {
			done() { return chain; },
			fail( cb ) { setTimeout( () => cb( { responseJSON: { message: 'The model took too long to reply.' } } ), 300 ); return chain; },
		};
		return chain;
	};
} );
await err.click( '.shapeforms-ai-card' );
await err.fill( '.shapeforms-ai-modal__input', 'A survey' );
await err.click( '.shapeforms-ai-modal__submit' );
await err.waitForTimeout( 700 );
chk( 'skeleton cleared after failure', await err.locator( '.shapeforms-ai-modal__skeleton' ).isHidden() );
chk( 'chips return after failure', await err.locator( '.shapeforms-ai-modal__suggestions' ).isVisible() );
chk( 'error shown after failure', ( await err.locator( '.shapeforms-ai-modal__notice' ).textContent() ) === 'The model took too long to reply.' );
chk( 'submit re-enabled after failure', await err.locator( '.shapeforms-ai-modal__submit' ).isEnabled() );

// Retrying must not leave the old error sitting under the new preview.
await err.evaluate( () => {
	jQuery.ajax = function () {
		const chain = { done() { return chain; }, fail() { return chain; } };
		return chain;
	};
} );
await err.click( '.shapeforms-ai-modal__submit' );
await err.waitForTimeout( 300 );
chk( 'stale error cleared on retry', await err.locator( '.shapeforms-ai-modal__notice' ).isHidden() );
chk( 'skeleton back on retry', await err.locator( '.shapeforms-ai-modal__skeleton' ).isVisible() );

await browser.close();
console.log( '\n' + pass + ' passed, ' + fail + ' failed' );
process.exit( fail ? 1 : 0 );
