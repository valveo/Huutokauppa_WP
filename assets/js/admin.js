/* Item image gallery on the edit screen: pick from the media library, reorder, remove. */
jQuery( function ( $ ) {
	var $list = $( '[data-hk-gallery]' );
	var $input = $( '[data-hk-gallery-input]' );
	if ( ! $list.length ) {
		return;
	}

	function sync() {
		$input.val( $list.children( 'li' ).map( function () {
			return $( this ).data( 'id' );
		} ).get().join( ',' ) );
	}

	$list.sortable( { update: sync, tolerance: 'pointer' } );

	$list.on( 'click', '.hk-admin-gallery__remove', function () {
		$( this ).closest( 'li' ).remove();
		sync();
	} );

	var frame;
	$( '[data-hk-gallery-add]' ).on( 'click', function ( e ) {
		e.preventDefault();
		if ( ! frame ) {
			frame = wp.media( {
				title: HKAdmin.title,
				button: { text: HKAdmin.button },
				library: { type: 'image' },
				multiple: 'add',
			} );
			frame.on( 'select', function () {
				frame.state().get( 'selection' ).each( function ( att ) {
					var a = att.toJSON();
					if ( $list.children( '[data-id="' + a.id + '"]' ).length ) {
						return;
					}
					var src = ( a.sizes && a.sizes.thumbnail ) ? a.sizes.thumbnail.url : a.url;
					var $li = $( '<li/>' ).attr( 'data-id', a.id );
					$li.append( $( '<img/>' ).attr( { src: src, alt: '' } ) );
					$li.append( $( '<button type="button" class="hk-admin-gallery__remove">×</button>' ).attr( 'aria-label', HKAdmin.remove ) );
					$list.append( $li );
				} );
				sync();
			} );
		}
		frame.open();
	} );
} );
