/**
 * Huutokauppa front end: countdowns, live updates, bidding without page reloads
 * and the image gallery. Works on top of plain HTML forms, so everything keeps
 * working (with page reloads) if this script fails to load.
 */
( function () {
	'use strict';

	var C = window.HK;
	if ( ! C ) {
		return;
	}
	var T = C.i18n;
	var offset = C.now * 1000 - Date.now(); // server clock minus browser clock
	var pollTimer = null;

	function serverNow() {
		return Date.now() + offset;
	}

	function pad( n ) {
		return n < 10 ? '0' + n : String( n );
	}

	function duration( ms ) {
		var s = Math.max( 0, Math.floor( ms / 1000 ) );
		var d = Math.floor( s / 86400 );
		s -= d * 86400;
		var h = Math.floor( s / 3600 );
		s -= h * 3600;
		var m = Math.floor( s / 60 );
		s -= m * 60;
		return ( d > 0 ? d + ' ' + T.days + ' ' : '' ) + pad( h ) + ':' + pad( m ) + ':' + pad( s );
	}

	function format( text, value ) {
		return text.replace( '%s', value );
	}

	/* ---------------------------------------------------------------
	 * Countdowns
	 * ------------------------------------------------------------- */

	function tick() {
		var now = serverNow();
		var expired = false;
		document.querySelectorAll( '[data-hk-countdown]' ).forEach( function ( el ) {
			var end = parseInt( el.getAttribute( 'data-hk-end' ), 10 ) * 1000;
			var start = parseInt( el.getAttribute( 'data-hk-start' ) || '0', 10 ) * 1000;
			var wrap = el.closest( '[data-hk-item]' );
			var state = ( wrap && wrap.getAttribute( 'data-hk-state' ) ) || 'live';
			var text;
			if ( ! end ) {
				text = '';
			} else if ( start && now < start ) {
				text = T.startsIn + ' ' + duration( start - now );
			} else if ( now >= end || ( state !== 'live' && state !== 'upcoming' ) ) {
				text = T.ended;
				if ( state === 'live' && ! el.hasAttribute( 'data-hk-expired' ) ) {
					el.setAttribute( 'data-hk-expired', '1' );
					expired = true;
				}
			} else {
				text = duration( end - now );
				el.classList.toggle( 'is-urgent', end - now < 5 * 60 * 1000 );
			}
			if ( el.textContent !== text ) {
				el.textContent = text;
			}
		} );
		if ( expired ) {
			poll(); // Confirm with the server; the end may have been extended.
		}
	}

	/* ---------------------------------------------------------------
	 * Live updates
	 * ------------------------------------------------------------- */

	function itemIds() {
		var ids = {};
		document.querySelectorAll( '[data-hk-item]' ).forEach( function ( el ) {
			ids[ el.getAttribute( 'data-hk-item' ) ] = true;
		} );
		return Object.keys( ids );
	}

	function request( method, path, body ) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': C.nonce },
		};
		if ( body ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}
		return fetch( C.rest + path, opts ).then( function ( r ) {
			return r.json().then( function ( data ) {
				data._status = r.status;
				return data;
			} );
		} );
	}

	function poll() {
		var ids = itemIds();
		if ( ! ids.length ) {
			return;
		}
		var history = document.querySelector( '[data-hk-history]' ) ? '&history=1' : '';
		// The endpoint accepts at most 50 ids per request.
		for ( var i = 0; i < ids.length; i += 50 ) {
			request( 'GET', 'status?ids=' + ids.slice( i, i + 50 ).join( ',' ) + history ).then( function ( data ) {
				if ( data && data.now ) {
					offset = data.now * 1000 - Date.now();
				}
				( data.items || [] ).forEach( apply );
			} ).catch( function () {} );
		}
	}

	function escapeHtml( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	function renderHistory( body, rows ) {
		if ( ! rows.length ) {
			body.innerHTML = '<tr><td colspan="3">' + escapeHtml( T.noBids ) + '</td></tr>';
			return;
		}
		body.innerHTML = rows.map( function ( h ) {
			return '<tr' + ( h.mine ? ' class="is-mine"' : '' ) + '><td>' + escapeHtml( h.bidder ) +
				( h.mine ? ' <em>(' + escapeHtml( T.you ) + ')</em>' : '' ) + '</td><td class="hk-num">' +
				escapeHtml( h.amount ) + ( h.auto ? ' <small>' + escapeHtml( T.auto ) + '</small>' : '' ) +
				'</td><td>' + escapeHtml( h.time ) + '</td></tr>';
		} ).join( '' );
	}

	function apply( s ) {
		document.querySelectorAll( '[data-hk-item="' + s.id + '"]' ).forEach( function ( wrap ) {
			var before = wrap.getAttribute( 'data-hk-state' );
			wrap.querySelectorAll( '[data-hk-field]' ).forEach( function ( el ) {
				var key = el.getAttribute( 'data-hk-field' );
				if ( key === 'state_label' ) {
					return;
				}
				if ( s[ key ] !== undefined && s[ key ] !== null && el.textContent !== String( s[ key ] ) ) {
					el.textContent = s[ key ];
					el.classList.remove( 'hk-flash' );
					void el.offsetWidth; // restart the highlight animation
					el.classList.add( 'hk-flash' );
				}
			} );
			wrap.querySelectorAll( '[data-hk-countdown]' ).forEach( function ( el ) {
				if ( String( s.end ) !== el.getAttribute( 'data-hk-end' ) ) {
					el.setAttribute( 'data-hk-end', s.end );
					el.removeAttribute( 'data-hk-expired' );
				}
			} );
			var live = s.state === 'live';
			wrap.querySelectorAll( '[data-hk-leading]' ).forEach( function ( el ) {
				el.hidden = ! ( s.leading && live );
			} );
			wrap.querySelectorAll( '[data-hk-outbid]' ).forEach( function ( el ) {
				el.hidden = !! s.leading;
			} );
			wrap.querySelectorAll( '[data-hk-mymax]' ).forEach( function ( el ) {
				el.hidden = s.my_max === null;
			} );
			var amount = wrap.querySelector( 'input[name="amount"]' );
			if ( amount && document.activeElement !== amount ) {
				amount.placeholder = Math.round( s.next_min ).toString();
			}
			if ( s.history ) {
				var body = wrap.querySelector( '[data-hk-history-body]' );
				if ( body ) {
					renderHistory( body, s.history );
				}
			}
			wrap.setAttribute( 'data-hk-state', s.state );
			// An item page that opens or closes is simplest to show by reloading it.
			if ( before !== s.state && wrap.hasAttribute( 'data-hk-history' ) && ( before === 'live' || s.state === 'live' ) ) {
				window.location.reload();
			}
		} );
	}

	/* ---------------------------------------------------------------
	 * Forms
	 * ------------------------------------------------------------- */

	function message( wrap, text, type ) {
		var box = wrap && wrap.querySelector( '.hk-live-msg' );
		if ( ! box ) {
			window.alert( text );
			return;
		}
		box.innerHTML = '<div class="hk-notice hk-notice--' + type + '" role="status">' + escapeHtml( text ) + '</div>';
		box.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
	}

	function onSubmit( e ) {
		var form = e.target.closest( 'form[data-hk-action]' );
		if ( ! form || ! window.fetch ) {
			return;
		}
		var action = form.getAttribute( 'data-hk-action' );
		var id = form.querySelector( '[name="item"]' ).value;
		var wrap = form.closest( '[data-hk-item]' );
		var button = form.querySelector( 'button[type="submit"]' );
		var call;

		if ( action === 'bid' ) {
			var amount = form.querySelector( '[name="amount"]' ).value.trim();
			if ( ! window.confirm( format( T.confirmBid, amount + ' ' + ( form.querySelector( '.hk-input__unit' ) || {} ).textContent ) ) ) {
				e.preventDefault();
				return;
			}
			call = [ 'POST', 'items/' + id + '/bid', { amount: amount } ];
		} else if ( action === 'autobid' ) {
			var max = form.querySelector( '[name="max"]' ).value.trim();
			if ( ! window.confirm( format( T.confirmAuto, max + ' ' + ( form.querySelector( '.hk-input__unit' ) || {} ).textContent ) ) ) {
				e.preventDefault();
				return;
			}
			call = [ 'POST', 'items/' + id + '/autobid', { max: max } ];
		} else if ( action === 'autobid-remove' ) {
			call = [ 'DELETE', 'items/' + id + '/autobid' ];
		} else if ( action === 'watch' ) {
			call = [ 'POST', 'items/' + id + '/watch' ];
		} else {
			return;
		}

		e.preventDefault();
		if ( button ) {
			button.disabled = true;
		}
		request( call[ 0 ], call[ 1 ], call[ 2 ] ).then( function ( r ) {
			if ( r._status === 401 || r._status === 403 ) {
				form.submit(); // Session or nonce expired: fall back to a normal post.
				return;
			}
			if ( action === 'watch' && r.ok ) {
				var b = form.querySelector( 'button' );
				b.textContent = r.watching ? T.unwatch : T.watch;
				b.setAttribute( 'aria-pressed', r.watching ? 'true' : 'false' );
			}
			if ( r.message ) {
				message( wrap, r.message, r.ok ? ( r.code === 'hk_outbid' ? 'warning' : 'success' ) : 'error' );
			}
			if ( r.snapshot ) {
				apply( r.snapshot );
				if ( action === 'bid' && r.ok ) {
					form.querySelector( '[name="amount"]' ).value = '';
				}
				if ( action === 'autobid-remove' ) {
					var maxInput = wrap && wrap.querySelector( '[name="max"]' );
					if ( maxInput ) {
						maxInput.value = '';
					}
				}
			}
		} ).catch( function () {
			message( wrap, T.error, 'error' );
		} ).finally( function () {
			if ( button ) {
				button.disabled = false;
			}
		} );
	}

	/* ---------------------------------------------------------------
	 * Gallery
	 * ------------------------------------------------------------- */

	function onClick( e ) {
		var thumb = e.target.closest( '.hk-gallery__thumb' );
		if ( ! thumb ) {
			return;
		}
		var gallery = thumb.closest( '.hk-gallery' );
		var link = gallery.querySelector( '.hk-gallery__main' );
		var img = link && link.querySelector( 'img' );
		if ( ! img ) {
			return;
		}
		img.removeAttribute( 'srcset' );
		img.removeAttribute( 'sizes' );
		img.src = thumb.getAttribute( 'data-large' );
		link.href = thumb.getAttribute( 'data-full' );
		gallery.querySelectorAll( '.hk-gallery__thumb' ).forEach( function ( t ) {
			t.classList.toggle( 'is-active', t === thumb );
		} );
	}

	/* --------------------------------------------------------------- */

	function start() {
		document.addEventListener( 'submit', onSubmit );
		document.addEventListener( 'click', onClick );
		tick();
		setInterval( tick, 1000 );
		if ( itemIds().length ) {
			pollTimer = setInterval( function () {
				if ( ! document.hidden ) {
					poll();
				}
			}, Math.max( 3, C.poll ) * 1000 );
			document.addEventListener( 'visibilitychange', function () {
				if ( ! document.hidden ) {
					poll();
				}
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
