( function ( global ) {
	'use strict';

	var handlers = global.YsEcAccountHandlers;
	if ( ! handlers || typeof handlers.registerFulfillmentProviderAdapter !== 'function' ) return;

	var RESULT_CODE_PATTERN = /^[A-Za-z0-9]{32}$/;
	var IDENTIFIER_PATTERN = /^[A-Za-z0-9_.:-]{1,100}$/;
	var REQUEST_TIMEOUT_MS = 15000;

	function responseData( response ) {
		if ( response && false === response.success ) {
			throw new Error( String( response.message || '綠界物流請求失敗，請稍後再試。' ) );
		}
		return response && response.data && typeof response.data === 'object' ? response.data : {};
	}

	function safeText( value, maxLength ) {
		if ( typeof value !== 'string' && typeof value !== 'number' ) return '';
		value = String( value ).trim();
		return value && value.length <= maxLength ? value : '';
	}

	function normalizeContext( raw ) {
		var subscriptionId = Number( raw && raw.subscription_id );
		var shippingId = safeText( raw && raw.shipping_method_id, 100 );
		var providerId = safeText( raw && raw.provider_id, 50 );
		if ( ! raw || typeof raw.request !== 'function'
			|| ! Number.isInteger( subscriptionId ) || subscriptionId < 1
			|| ! IDENTIFIER_PATTERN.test( shippingId ) || 'ecpay' !== providerId ) {
			throw new Error( '訂閱選店請求缺少綁定目前會員頁面的資料。' );
		}

		var returnUrl;
		try {
			returnUrl = new URL( String( raw.return_url || '' ), global.location && global.location.href );
		} catch ( error ) {
			throw new Error( '訂閱選店返回位置無法驗證。' );
		}
		if ( ! /^https?:$/.test( returnUrl.protocol ) ) {
			throw new Error( '訂閱選店返回位置無法驗證。' );
		}
		returnUrl.searchParams.delete( 'ys_ec_store_result' );

		return {
			subscriptionId: subscriptionId,
			shippingId: shippingId,
			cartScope: 'sub_' + subscriptionId,
			request: raw.request,
			returnUrl: returnUrl.toString(),
			savedAddress: raw.saved_address && typeof raw.saved_address === 'object' ? raw.saved_address : null
		};
	}

	function call( context, method, endpoint, body, timeoutMessage ) {
		return new Promise( function ( resolve, reject ) {
			var settled = false;
			var timer = global.setTimeout( function () {
				if ( settled ) return;
				settled = true;
				global.clearTimeout( timer );
				reject( new Error( timeoutMessage ) );
			}, REQUEST_TIMEOUT_MS );

			Promise.resolve().then( function () {
				return context.request( method, endpoint, body || null, { cache: 'no-store' } );
			} ).then( function ( response ) {
				if ( settled ) return;
				settled = true;
				global.clearTimeout( timer );
				resolve( response );
			}, function ( error ) {
				if ( settled ) return;
				settled = true;
				global.clearTimeout( timer );
				reject( error );
			} );
		} );
	}

	function validateSelection( response, context, requireCallbackContext ) {
		var data = responseData( response );
		var token = safeText( data.selection_token, 255 );
		var storeId = safeText( data.cvs_store_id || data.store_id, 20 );
		var storeName = safeText( data.cvs_store_name || data.store_name, 100 );
		var storeAddress = safeText( data.cvs_store_address || data.cvs_store_addr || data.store_address, 255 );
		if ( ! token || /[^\x20-\x7e]/.test( token ) || ! storeId || ! storeName || ! storeAddress
			|| context.shippingId !== safeText( data.shipping_id, 100 )
			|| context.cartScope !== safeText( data.cart_scope, 32 )
			|| ( requireCallbackContext && 'subscription' !== safeText( data.context, 20 ) ) ) {
			throw new Error( '綠界門市結果與目前訂閱不相符，請重新選擇。' );
		}

		return {
			selection_token: token,
			cvs_store_id: storeId,
			cvs_store_name: storeName,
			cvs_store_address: storeAddress
		};
	}

	function openPopup( context ) {
		if ( typeof global.open !== 'function' ) throw new Error( '瀏覽器無法開啟綠界選店視窗。' );
		var name = 'ys_ec_ecpay_subscription_' + context.subscriptionId + '_' + String( Date.now() )
			+ '_' + String( Math.floor( Math.random() * 1000000 ) );
		var popup = global.open( 'about:blank', name, 'popup=yes,width=960,height=760,resizable=yes,scrollbars=yes' );
		if ( ! popup ) throw new Error( '請允許彈出式視窗後再選擇門市。' );
		try { popup.focus(); } catch ( error ) {}
		return { window: popup, name: name };
	}

	function submitMapForm( popup, mapData ) {
		var actionUrl;
		try { actionUrl = new URL( String( mapData.action_url || '' ) ); } catch ( error ) { actionUrl = null; }
		if ( ! actionUrl || 'https:' !== actionUrl.protocol || ! mapData.fields || typeof mapData.fields !== 'object' ) {
			throw new Error( '綠界選店表單無法驗證。' );
		}
		var form = document.createElement( 'form' );
		form.method = 'POST';
		form.action = actionUrl.toString();
		form.target = popup.name;
		Object.keys( mapData.fields ).forEach( function ( key ) {
			var value = mapData.fields[ key ];
			if ( typeof value !== 'string' && typeof value !== 'number' ) {
				throw new Error( '綠界選店表單欄位無法驗證。' );
			}
			var input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = key;
			input.value = String( value );
			form.appendChild( input );
		} );
		document.body.appendChild( form );
		form.submit();
		if ( typeof form.remove === 'function' ) form.remove();
	}

	function waitForResultCode( popup, context ) {
		return new Promise( function ( resolve, reject ) {
			var settled = false;
			var deadline = Date.now() + 120000;
			var timer = global.setInterval( function () {
				if ( settled ) return;
				if ( popup.closed ) {
					settled = true;
					global.clearInterval( timer );
					reject( new Error( '綠界選店視窗已關閉，請重新選擇。' ) );
					return;
				}
				try {
					var current = new URL( String( popup.location.href || '' ), context.returnUrl );
					var expected = new URL( context.returnUrl );
					if ( current.origin === expected.origin ) {
						var code = current.searchParams.get( 'ys_ec_store_result' ) || '';
						if ( RESULT_CODE_PATTERN.test( code ) ) {
							settled = true;
							global.clearInterval( timer );
							resolve( code );
							return;
						}
					}
				} catch ( error ) {
					// Cross-origin access while the popup is on ECPay is expected.
				}
				if ( Date.now() >= deadline ) {
					settled = true;
					global.clearInterval( timer );
					reject( new Error( '綠界選店逾時，請重新選擇。' ) );
				}
			}, 250 );
		} );
	}

	function closePopup( popup ) {
		try { if ( popup && ! popup.closed ) popup.close(); } catch ( error ) {}
	}

	function selectStore( rawContext ) {
		var context;
		var popup;
		try {
			context = normalizeContext( rawContext );
			popup = openPopup( context );
		} catch ( error ) {
			return Promise.reject( error );
		}

		return call( context, 'POST', '/stores/ecpay/map-url', {
			context: 'subscription',
			subscription_id: context.subscriptionId,
			shipping_id: context.shippingId,
			return_url: context.returnUrl
		}, '綠界選店請求逾時，請稍後再試。' ).then( function ( response ) {
			submitMapForm( popup, responseData( response ) );
			return waitForResultCode( popup.window, context );
		} ).then( function ( code ) {
			return call(
				context,
				'GET',
				'/ecpay/store-result?code=' + encodeURIComponent( code )
					+ '&cart_scope=' + encodeURIComponent( context.cartScope ),
				null,
				'綠界門市結果認領逾時，請重新選擇。'
			);
		} ).then( function ( response ) {
			return validateSelection( response, context, true );
		} ).then( function ( selection ) {
			closePopup( popup.window );
			return selection;
		}, function ( error ) {
			closePopup( popup.window );
			throw error;
		} );
	}

	function reauthorizeSavedStore( rawContext ) {
		var context;
		try { context = normalizeContext( rawContext ); } catch ( error ) { return Promise.reject( error ); }
		var addressId = Number( context.savedAddress && context.savedAddress.id );
		if ( ! Number.isInteger( addressId ) || addressId < 1 ) {
			return Promise.reject( new Error( '已儲存門市的會員地址無法驗證。' ) );
		}

		return call( context, 'POST', '/stores/ecpay/reauthorize', {
			context: 'subscription',
			subscription_id: context.subscriptionId,
			address_id: addressId,
			shipping_id: context.shippingId
		}, '綠界已儲存門市重新授權逾時，請稍後再試。' ).then( function ( response ) {
			return validateSelection( response, context, false );
		} );
	}

	handlers.registerFulfillmentProviderAdapter( 'ecpay', {
		selectStore: selectStore,
		reauthorizeSavedStore: reauthorizeSavedStore
	} );
} )( window );
