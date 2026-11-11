( function ( window, document ) {
	var activeDrag = null;

	// Every live Sortable, so a drag can be routed to a container the pointer is
	// merely NEAR rather than exactly over. Detached entries are pruned lazily
	// during a drag — the canvas replaces these elements wholesale on every
	// render, so the list would otherwise grow forever.
	var instances = [];

	function matches( element, selector ) {
		if ( ! element || 1 !== element.nodeType ) {
			return false;
		}

		return element.matches( selector );
	}

	function closestMatch( element, root, selector ) {
		while ( element && element !== root ) {
			if ( matches( element, selector ) ) {
				return element;
			}

			element = element.parentNode;
		}

		return matches( root, selector ) ? root : null;
	}

	function getItems( container, selector ) {
		return Array.prototype.slice.call( container.querySelectorAll( selector ) );
	}

	function getIndex( container, element, selector ) {
		return getItems( container, selector ).indexOf( element );
	}

	function clearPlaceholder() {
		if ( activeDrag && activeDrag.placeholder && activeDrag.placeholder.parentNode ) {
			activeDrag.placeholder.parentNode.removeChild( activeDrag.placeholder );
		}
	}

	function cleanup() {
		clearPlaceholder();
		activeDrag = null;
	}

	function canAccept( sortable ) {
		var sourceGroup;
		var targetGroup;

		if ( ! activeDrag ) {
			return false;
		}

		if ( activeDrag.source === sortable.el ) {
			// Dropping back onto the same list (i.e. reordering within it).
			// Reject this when internal sorting is disabled (`sort: false`) or
			// when the list is a clone-source palette (`pull: 'clone'`), such as
			// the field library: its items may be cloned out to the canvas but
			// must never be reordered or dropped back into the sidebar itself.
			var selfGroup = sortable.options.group || {};

			return false !== sortable.options.sort && 'clone' !== selfGroup.pull;
		}

		sourceGroup = activeDrag.sortable.options.group || {};
		targetGroup = sortable.options.group || {};

		if ( sourceGroup.name && targetGroup.name && sourceGroup.name !== targetGroup.name ) {
			return false;
		}

		return false !== targetGroup.put;
	}

	function createPlaceholder( element ) {
		var placeholder = document.createElement( 'div' );

		placeholder.className = 'shapeforms-sortable-placeholder';
		placeholder.style.height = element.offsetHeight + 'px';
		return placeholder;
	}

	function placePlaceholder( sortable, event ) {
		var container = sortable.el;
		var selector = sortable.options.draggable;
		var items = getItems( container, selector ).filter(
			function ( item ) {
				return item !== activeDrag.placeholder && item !== activeDrag.dragEl;
			}
		);
		var inserted = false;

		items.forEach(
			function ( item ) {
				var rect = item.getBoundingClientRect();

				if ( ! inserted && event.clientY < rect.top + rect.height / 2 ) {
					container.insertBefore( activeDrag.placeholder, item );
					inserted = true;
				}
			}
		);

		if ( ! inserted ) {
			if ( ! items.length ) {
				container.appendChild( activeDrag.placeholder );
			} else {
				// Pointer is past the last item: drop directly AFTER it rather
				// than at the very end of the container. The rows container also
				// holds trailing non-draggable siblings (the canvas Submit button
				// and the "Add Row" button), and appending would place a row below
				// them — which must never happen. The last item's nextSibling is
				// those buttons (rows) or null (a column with no trailing element,
				// where insertBefore(_, null) === appendChild, the prior behaviour).
				var ref = items[ items.length - 1 ].nextSibling;

				if ( ref !== activeDrag.placeholder ) {
					container.insertBefore( activeDrag.placeholder, ref );
				}
			}
		}
	}

	/**
	 * Finish a drop into `sortable`.
	 *
	 * Shared by the container's own drop listener and by the proximity
	 * fallback below, which completes drops for a container the pointer never
	 * actually entered.
	 */
	function completeDrop( sortable, event ) {
		var selector = sortable.options.draggable || '> *';
		var item;
		var newIndex;
		var draggableItems;

		placePlaceholder( sortable, event );

		item = activeDrag.cloneMode ? activeDrag.dragEl.cloneNode( true ) : activeDrag.dragEl;

		// Count only draggable siblings before the placeholder (excluding the dragged element itself) to get the correct data index.
		draggableItems = getItems( sortable.el, selector ).filter( function ( el ) {
			return el !== activeDrag.dragEl && el !== activeDrag.placeholder;
		} );
		newIndex = 0;
		draggableItems.forEach( function ( el ) {
			// If the element comes before the placeholder in DOM order, increment index.
			if ( activeDrag.placeholder.compareDocumentPosition( el ) & Node.DOCUMENT_POSITION_PRECEDING ) {
				newIndex++;
			}
		} );

		sortable.el.insertBefore( item, activeDrag.placeholder );

		var sortableEvent = {
			item: item,
			from: activeDrag.source,
			to: sortable.el,
			oldIndex: activeDrag.sourceIndex,
			newIndex: newIndex
		};

		if ( activeDrag.cloneMode && typeof sortable.options.onAdd === 'function' ) {
			sortable.options.onAdd( sortableEvent );
		}

		if ( ! activeDrag.cloneMode && typeof sortable.options.onEnd === 'function' ) {
			sortable.options.onEnd( sortableEvent );
		}

		cleanup();
	}

	// --- Proximity drop routing -----------------------------------------
	// Native drag-and-drop offers a drop only where a listener calls
	// preventDefault, and those listeners sit on the field containers
	// themselves. Everything the canvas draws BETWEEN two containers is
	// therefore dead: the row header strip, the row's own padding, the gap
	// between rows. That dead band looks identical to the live gap between two
	// fields inside one column, and in conversational mode it is roughly 80px
	// tall between one question card and the next (128px around a multi-column
	// card, whose visible padding belongs to the row, not to the container).
	// So most of what an author aims at silently refuses the drop.
	//
	// An unclaimed drag is routed to the nearest container that would accept
	// it, which is what the author meant by aiming at the space between two
	// cards.
	var NEAR_Y = 80; // Vertical reach, in px. Must exceed half the widest dead band.
	var NEAR_X = 16; // Horizontal reach: the column gutter, and no more.

	function nearestAcceptable( event ) {
		var best = null;
		var bestDistance = Infinity;
		var i;
		var sortable;
		var rect;
		var dx;
		var dy;
		var distance;

		for ( i = instances.length - 1; i >= 0; i-- ) {
			sortable = instances[ i ];

			if ( ! document.body.contains( sortable.el ) ) {
				instances.splice( i, 1 );
				continue;
			}

			if ( ! canAccept( sortable ) ) {
				continue;
			}

			rect = sortable.el.getBoundingClientRect();

			if ( ! rect.width && ! rect.height ) {
				continue;
			}

			dx = Math.max( rect.left - event.clientX, 0, event.clientX - rect.right );
			dy = Math.max( rect.top - event.clientY, 0, event.clientY - rect.bottom );

			// Horizontal reach is deliberately tiny. A generous one would let a
			// drag held over the field library snap into the canvas beside it.
			if ( dx > NEAR_X || dy > NEAR_Y ) {
				continue;
			}

			distance = Math.sqrt( dx * dx + dy * dy );

			if ( distance < bestDistance ) {
				bestDistance = distance;
				best = sortable;
			}
		}

		return best;
	}

	// Bubble phase, so a container that claimed the event has already called
	// preventDefault and this stands down.
	document.addEventListener( 'dragover', function ( event ) {
		var sortable;

		if ( ! activeDrag || event.defaultPrevented ) {
			return;
		}

		sortable = nearestAcceptable( event );

		if ( ! sortable ) {
			// Out of reach of everything. Take the placeholder away rather than
			// leaving it sitting in the last container the pointer passed over.
			clearPlaceholder();
			return;
		}

		event.preventDefault();
		placePlaceholder( sortable, event );
	} );

	document.addEventListener( 'drop', function ( event ) {
		var sortable;

		if ( ! activeDrag || event.defaultPrevented ) {
			return;
		}

		// Recomputed at the drop point rather than trusting what the last
		// dragover decided — the two can differ, and a stale target would drop
		// the field somewhere the author never hovered.
		sortable = nearestAcceptable( event );

		if ( ! sortable ) {
			cleanup();
			return;
		}

		event.preventDefault();
		completeDrop( sortable, event );
	} );

	function Sortable( el, options ) {
		this.el = el;
		this.options = options || {};
		this.bind();
	}

	// --- Auto-scroll while dragging -------------------------------------
	// Native HTML5 drag-and-drop does NOT auto-scroll scrollable containers
	// (and only inconsistently scrolls the window), so a drop target below the
	// fold is unreachable — on small viewports this made it impossible to drop
	// a row at the bottom of the canvas. While a drag is active we watch the
	// pointer and, when it nears the top/bottom edge of the nearest scrollable
	// ancestor (or the window), scroll continuously until it leaves the edge.
	var SCROLL_EDGE = 60;  // px from an edge that triggers scrolling.
	var SCROLL_SPEED = 14; // px scrolled per animation frame.
	var scrollState = { frame: null, target: null, dir: 0 };

	function isScrollable( el ) {
		var overflowY;

		if ( ! el || 1 !== el.nodeType ) {
			return false;
		}

		overflowY = window.getComputedStyle( el ).overflowY;

		return ( 'auto' === overflowY || 'scroll' === overflowY || 'overlay' === overflowY ) &&
			el.scrollHeight > el.clientHeight;
	}

	function findScrollable( el ) {
		while ( el && el !== document.body && el !== document.documentElement ) {
			if ( isScrollable( el ) ) {
				return el;
			}

			el = el.parentNode;
		}

		return null;
	}

	function stopAutoScroll() {
		if ( scrollState.frame ) {
			window.cancelAnimationFrame( scrollState.frame );
		}

		scrollState.frame = null;
		scrollState.target = null;
		scrollState.dir = 0;
	}

	function stepAutoScroll() {
		scrollState.frame = null;

		if ( ! activeDrag || ! scrollState.dir ) {
			return;
		}

		if ( scrollState.target ) {
			scrollState.target.scrollTop += scrollState.dir * SCROLL_SPEED;
		} else {
			window.scrollBy( 0, scrollState.dir * SCROLL_SPEED );
		}

		scrollState.frame = window.requestAnimationFrame( stepAutoScroll );
	}

	function updateAutoScroll( event ) {
		var under;
		var target;
		var rect;
		var top;
		var bottom;
		var dir = 0;

		if ( ! activeDrag ) {
			stopAutoScroll();
			return;
		}

		under = document.elementFromPoint( event.clientX, event.clientY );
		target = findScrollable( under );

		if ( target ) {
			rect = target.getBoundingClientRect();
			top = rect.top;
			bottom = rect.bottom;
		} else {
			top = 0;
			bottom = window.innerHeight || document.documentElement.clientHeight;
		}

		if ( event.clientY < top + SCROLL_EDGE ) {
			dir = -1;
		} else if ( event.clientY > bottom - SCROLL_EDGE ) {
			dir = 1;
		}

		scrollState.target = target;
		scrollState.dir = dir;

		if ( dir && ! scrollState.frame ) {
			scrollState.frame = window.requestAnimationFrame( stepAutoScroll );
		} else if ( ! dir ) {
			stopAutoScroll();
		}
	}

	// Registered once at module load (not per Sortable instance) so it drives
	// auto-scroll for every sortable — rows, columns and the field library.
	document.addEventListener( 'dragover', updateAutoScroll );
	document.addEventListener( 'drop', stopAutoScroll );
	document.addEventListener( 'dragend', stopAutoScroll );

	Sortable.prototype.bind = function () {
		var sortable = this;
		var selector = sortable.options.draggable || '> *';
		var handleSelector = sortable.options.handle || null;

		if ( sortable.el.__shapeformsSortable ) {
			return;
		}

		sortable.el.__shapeformsSortable = sortable;
		instances.push( sortable );

		sortable.el.addEventListener( 'dragstart', function ( event ) {
			var item;
			var group;
			var handle;

			// A nested sortable already claimed this drag — do not interfere.
			if ( activeDrag ) {
				return;
			}

			item = closestMatch( event.target, sortable.el, selector );
			group = sortable.options.group || {};

			if ( ! item ) {
				return;
			}

			if ( handleSelector ) {
				handle = closestMatch( event.target, item, handleSelector );

				if ( ! handle ) {
					return;
				}
			}

			activeDrag = {
				sortable: sortable,
				source: sortable.el,
				sourceIndex: getIndex( sortable.el, item, selector ),
				dragEl: item,
				placeholder: createPlaceholder( item ),
				cloneMode: 'clone' === group.pull
			};

			if ( event.dataTransfer ) {
				event.dataTransfer.effectAllowed = activeDrag.cloneMode ? 'copyMove' : 'move';
				event.dataTransfer.setData( 'text/plain', item.getAttribute( 'data-field-type' ) || item.getAttribute( 'data-field-id' ) || '' );
			}
		} );

		sortable.el.addEventListener( 'dragover', function ( event ) {
			if ( ! canAccept( sortable ) ) {
				return;
			}

			event.preventDefault();
			placePlaceholder( sortable, event );
		} );

		sortable.el.addEventListener( 'drop', function ( event ) {
			if ( ! canAccept( sortable ) ) {
				return;
			}

			event.preventDefault();
			completeDrop( sortable, event );
		} );

		sortable.el.addEventListener( 'dragend', function () {
			cleanup();
		} );
	};

	window.Sortable = {
		create: function ( el, options ) {
			return new Sortable( el, options );
		}
	};
}( window, document ) );
