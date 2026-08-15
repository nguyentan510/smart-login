/**
 * Smart Login — Account Hub JS
 *
 * Tab switching, Anchor Navigation, Order Pipeline Live Search, Mobile Auto-Center,
 * & Smart Address Book CRUD and Ward Cascade.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var hub = document.querySelector( '[data-sl-hub]' );
		if ( ! hub ) {
			return;
		}

		var navItems = hub.querySelectorAll( '[data-sl-hub-tab]' );
		var tabPanels = hub.querySelectorAll( '[data-sl-hub-panel]' );
		var logoutTriggers = hub.querySelectorAll( '[data-sl-logout-trigger]' );
		var logoutModal = document.querySelector( '[data-sl-logout-modal]' );
		var logoutCancel = logoutModal && logoutModal.querySelector( '[data-sl-logout-cancel]' );

		var domRestBase = hub.getAttribute( 'data-rest-url' );
		var domNonce = hub.getAttribute( 'data-rest-nonce' );

		var restBase = domRestBase
			|| ( window.OmniWPHubData && window.OmniWPHubData.restUrl )
			|| ( window.OmniWPData && window.OmniWPData.restUrl )
			|| '/wp-json/omniwp/v1/';
		var restNonce = domNonce
			|| ( window.OmniWPHubData && window.OmniWPHubData.nonce )
			|| ( window.OmniWPData && window.OmniWPData.nonce )
			|| '';

		// -----------------------------------------------------------------
		// Helper: Map Hash/Anchor to Tab Key
		// -----------------------------------------------------------------
		function resolveTabKey( hash ) {
			if ( ! hash ) {
				return null;
			}

			var clean = hash.replace( /^#+/, '' ).split( '?' )[0];

			if ( clean === 'sl-section-contact' || clean === 'contact' ) {
				return 'contact';
			}
			if ( clean === 'sl-section-profile' || clean === 'profile' ) {
				return 'profile';
			}
			if ( clean === 'sl-section-address' || clean === 'address' ) {
				return 'address';
			}
			if ( clean === 'sl-section-password' || clean === 'security' || clean === 'password' ) {
				return 'security';
			}
			if ( clean === 'orders' ) {
				return 'orders';
			}
			if ( clean === 'vouchers' || clean === 'sl-section-vouchers' || clean === 'coupons' ) {
				return 'vouchers';
			}

			if ( hub.querySelector( '[data-sl-hub-panel="' + clean + '"]' ) ) {
				return clean;
			}

			return null;
		}

		// -----------------------------------------------------------------
		// Tab Switching Logic & Auto-Center Active Tab
		// -----------------------------------------------------------------
		function activateTab( targetKey, targetAnchor, focusFieldKey ) {
			if ( ! targetKey ) {
				return;
			}

			navItems.forEach( function ( item ) {
				var key = item.getAttribute( 'data-sl-hub-tab' );
				if ( key === targetKey ) {
					item.classList.add( 'is-active' );
					if ( typeof item.scrollIntoView === 'function' && window.innerWidth < 768 ) {
						item.scrollIntoView( { behavior: 'smooth', inline: 'center', block: 'nearest' } );
					}
				} else {
					item.classList.remove( 'is-active' );
				}
			} );

			tabPanels.forEach( function ( panel ) {
				var key = panel.getAttribute( 'data-sl-hub-panel' );
				if ( key === targetKey ) {
					panel.style.display = 'block';
				} else {
					panel.style.display = 'none';
				}
			} );

			var cleanHash = targetKey;
			if ( targetAnchor ) {
				cleanHash = targetAnchor.replace( /^#+/, '' );
			}

			if ( history.replaceState ) {
				history.replaceState( null, null, '#' + cleanHash );
			} else {
				window.location.hash = '#' + cleanHash;
			}

			// Focus target field if provided (e.g. from completion card)
			if ( focusFieldKey ) {
				setTimeout( function () {
					var fieldMap = {
						'dob': '#sl-dob, input[name="OmniWP_dob"]',
						'gender': 'input[name="OmniWP_gender"]',
						'name': '#OmniWP_full_name, input[name="OmniWP_full_name"]',
						'address': '[data-sl-address-add]',
						'email': '#OmniWP_email, input[name="OmniWP_email"]',
						'phone': '#OmniWP_phone, input[name="OmniWP_phone"]',
						'password': '#OmniWP_password_current, input[name="password_current"]'
					};
					var selector = fieldMap[ focusFieldKey ] || '#' + focusFieldKey;
					var targetField = document.querySelector( selector );
					if ( targetField ) {
						targetField.scrollIntoView( { behavior: 'smooth', block: 'center' } );
						if ( typeof targetField.focus === 'function' ) {
							targetField.focus();
						}
					}
				}, 80 );
			} else if ( targetAnchor && targetAnchor !== targetKey ) {
				var targetEl = document.getElementById( targetAnchor.replace( /^#+/, '' ) );
				if ( targetEl ) {
					targetEl.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}
			}
		}

		// Global Link Click Delegation for internal section/tab anchors
		document.addEventListener( 'click', function ( e ) {
			var link = e.target.closest( 'a[href*="#"]' );
			if ( ! link ) {
				return;
			}

			var href = link.getAttribute( 'href' );
			if ( ! href || href === '#' ) {
				return;
			}

			if ( link.hasAttribute( 'data-sl-logout-trigger' ) ) {
				return;
			}

			var targetKey = resolveTabKey( href );
			if ( targetKey ) {
				e.preventDefault();
				var anchor = href.indexOf( '#' ) !== -1 ? href.substring( href.indexOf( '#' ) ) : targetKey;
				var targetFieldKey = link.getAttribute( 'data-sl-target' ) || null;
				activateTab( targetKey, anchor, targetFieldKey );
			}
		} );

		// Hash change listener for browser history (Back / Forward)
		window.addEventListener( 'hashchange', function () {
			if ( window.location.hash ) {
				var key = resolveTabKey( window.location.hash );
				if ( key ) {
					activateTab( key, window.location.hash );
				}
			}
		} );

		// Initial tab activation on load / F5
		var urlParams = new URLSearchParams( window.location.search );
		var tabParam = urlParams.get( 'tab' );
		var initialKey = tabParam ? resolveTabKey( tabParam ) : ( window.location.hash ? resolveTabKey( window.location.hash ) : null );
		if ( initialKey ) {
			activateTab( initialKey, window.location.hash || ( '#' + initialKey ) );
		}

		// -----------------------------------------------------------------
		// Mobile Settings Bottom Sheet (Gear ⚙️ Trigger)
		// -----------------------------------------------------------------
		var settingsTriggers = hub.querySelectorAll( '[data-sl-settings-trigger]' );
		var sheetBackdrop = document.querySelector( '[data-sl-settings-sheet-backdrop]' );
		var sheetClose = sheetBackdrop && sheetBackdrop.querySelector( '[data-sl-settings-sheet-close]' );
		var settingsActionLinks = document.querySelectorAll( '[data-sl-settings-action]' );

		function openSettingsSheet() {
			if ( sheetBackdrop && window.innerWidth < 768 ) {
				sheetBackdrop.removeAttribute( 'hidden' );
				sheetBackdrop.classList.add( 'is-open' );
				sheetBackdrop.style.setProperty( 'display', 'flex', 'important' );
				document.body.style.overflow = 'hidden';
			}
		}

		function closeSettingsSheet() {
			if ( sheetBackdrop ) {
				sheetBackdrop.setAttribute( 'hidden', '' );
				sheetBackdrop.classList.remove( 'is-open' );
				sheetBackdrop.style.setProperty( 'display', 'none', 'important' );
				document.body.style.overflow = '';
			}
		}

		// Ensure hidden initially
		if ( sheetBackdrop ) {
			sheetBackdrop.setAttribute( 'hidden', '' );
			sheetBackdrop.classList.remove( 'is-open' );
			sheetBackdrop.style.setProperty( 'display', 'none', 'important' );
		}

		settingsTriggers.forEach( function ( trigger ) {
			trigger.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				openSettingsSheet();
			} );
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-sl-settings-sheet-close]' ) ) {
				e.preventDefault();
				e.stopPropagation();
				closeSettingsSheet();
				return;
			}
			if ( sheetBackdrop && e.target === sheetBackdrop ) {
				e.preventDefault();
				closeSettingsSheet();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				closeSettingsSheet();
			}
		} );

		settingsActionLinks.forEach( function ( link ) {
			link.addEventListener( 'click', function ( e ) {
				var action = link.getAttribute( 'data-sl-settings-action' );
				closeSettingsSheet();
				if ( action === 'security' || action === 'password' ) {
					e.preventDefault();
					activateTab( 'security', action === 'password' ? 'sl-section-password' : 'sl-section-contact', action === 'password' ? 'password' : 'phone' );
				}
			} );
		} );

		// -----------------------------------------------------------------
		// Order Pipeline & Live Search
		// -----------------------------------------------------------------
		var pipelineItems = hub.querySelectorAll( '[data-sl-order-status]' );
		var searchInput = hub.querySelector( '[data-sl-orders-search]' );
		var ordersContainer = hub.querySelector( '[data-sl-orders-container]' );
		var currentStatus = 'all';
		var searchTimer = null;

		function fetchOrders() {
			if ( ! ordersContainer ) {
				return;
			}

			var searchVal = searchInput ? searchInput.value.trim() : '';
			var url = restBase + 'orders?status=' + encodeURIComponent( currentStatus ) + '&search=' + encodeURIComponent( searchVal );

			ordersContainer.style.opacity = '0.5';

			fetch( url, {
				headers: {
					'X-WP-Nonce': restNonce
				}
			} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				ordersContainer.style.opacity = '1';

				if ( data && data.html !== undefined ) {
					ordersContainer.innerHTML = data.html;
				}

				if ( data && data.counts ) {
					Object.keys( data.counts ).forEach( function ( key ) {
						var badge = hub.querySelector( '[data-sl-order-badge="' + key + '"]' );
						if ( badge ) {
							var count = parseInt( data.counts[ key ], 10 ) || 0;
							if ( count > 0 ) {
								badge.textContent = count;
								badge.style.display = 'inline-block';
							} else {
								badge.style.display = 'none';
							}
						}
					} );
				}
			} )
			.catch( function () {
				ordersContainer.style.opacity = '1';
			} );
		}

		pipelineItems.forEach( function ( item ) {
			item.addEventListener( 'click', function ( e ) {
				e.preventDefault();

				pipelineItems.forEach( function ( el ) { el.classList.remove( 'is-active' ); } );
				item.classList.add( 'is-active' );

				if ( typeof item.scrollIntoView === 'function' ) {
					item.scrollIntoView( { behavior: 'smooth', inline: 'center', block: 'nearest' } );
				}

				currentStatus = item.getAttribute( 'data-sl-order-status' ) || 'all';
				fetchOrders();
			} );
		} );

		if ( searchInput ) {
			searchInput.addEventListener( 'input', function () {
				if ( searchTimer ) {
					clearTimeout( searchTimer );
				}
				searchTimer = setTimeout( function () {
					fetchOrders();
				}, 300 );
			} );
		}

		if ( ordersContainer ) {
			fetchOrders();
		}

		// -----------------------------------------------------------------
		// Order Detail Modal Backdrop Popup
		// -----------------------------------------------------------------
		var orderModal = document.querySelector( '[data-sl-order-modal]' );
		var orderModalCloseBtns = orderModal ? orderModal.querySelectorAll( '[data-sl-order-modal-close]' ) : [];
		var orderModalLoading = orderModal ? orderModal.querySelector( '[data-sl-order-modal-loading]' ) : null;
		var orderModalContent = orderModal ? orderModal.querySelector( '[data-sl-order-modal-content]' ) : null;

		function closeOrderModal() {
			if ( orderModal ) {
				orderModal.classList.remove( 'is-open' );
				orderModal.setAttribute( 'aria-hidden', 'true' );
				document.body.style.overflow = '';
			}
		}

		function openOrderModal( orderId ) {
			if ( ! orderModal || ! orderId ) {
				return;
			}

			orderModal.classList.add( 'is-open' );
			orderModal.setAttribute( 'aria-hidden', 'false' );
			document.body.style.overflow = 'hidden';

			if ( orderModalLoading ) { orderModalLoading.style.display = 'block'; }
			if ( orderModalContent ) { orderModalContent.style.display = 'none'; }

			var numEl = orderModal.querySelector( '[data-sl-modal-num]' );
			if ( numEl ) { numEl.textContent = '#' + orderId; }

			var url = restBase + 'orders/' + encodeURIComponent( orderId );

			fetch( url, {
				headers: {
					'X-WP-Nonce': restNonce
				}
			} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				if ( ! data || ! data.order ) {
					if ( orderModalLoading ) {
						orderModalLoading.innerHTML = '<p style="color:#ef4444;">Không thể tải thông tin đơn hàng.</p>';
					}
					return;
				}

				var ord = data.order;

				if ( orderModalLoading ) { orderModalLoading.style.display = 'none'; }
				if ( orderModalContent ) { orderModalContent.style.display = 'flex'; }

				// Header
				if ( numEl ) { numEl.textContent = '#' + ( ord.number || ord.id ); }
				var dateEl = orderModal.querySelector( '[data-sl-modal-date]' );
				if ( dateEl ) { dateEl.textContent = 'Ngày đặt: ' + ( ord.date || '' ); }

				// Timeline
				var timelineEl = orderModal.querySelector( '[data-sl-modal-timeline]' );
				if ( timelineEl && ord.timeline ) {
					var isCancelled = !! ord.is_cancelled;
					var doneCount = ord.timeline.filter( function ( s ) { return s.done; } ).length;
					var totalSteps = ord.timeline.length;
					var fillPercent = totalSteps > 1 ? Math.min( 100, Math.round( ( ( doneCount - 1 ) / ( totalSteps - 1 ) ) * 100 ) ) : 0;
					if ( isCancelled ) { fillPercent = 100; }

					var fillStyle = 'width:' + fillPercent + '%;' + ( isCancelled ? 'background:#ef4444;' : '' );
					var tlHtml = '<div class="sl-invoice-timeline-track"><div class="sl-invoice-timeline-fill" style="' + fillStyle + '"></div></div>';

					ord.timeline.forEach( function ( step, sIdx ) {
						var stepClass = 'sl-invoice-timeline-step';
						if ( step.done ) { stepClass += ' is-done'; }
						if ( step.active ) { stepClass += ' is-active'; }
						if ( step.cancel ) { stepClass += ' is-cancelled'; }

						var iconHtml = step.cancel ? '✕' : ( step.done ? '✓' : ( sIdx + 1 ) );
						tlHtml += '<div class="' + stepClass + '">' +
							'<div class="sl-invoice-timeline-dot">' + iconHtml + '</div>' +
							'<span class="sl-invoice-timeline-label">' + step.label + '</span>' +
							( step.date ? '<span class="sl-invoice-timeline-date">' + step.date + '</span>' : '' ) +
							'</div>';
					} );
					timelineEl.innerHTML = tlHtml;
				}

				// Status Badge
				var statusBadgeEl = orderModal.querySelector( '[data-sl-modal-status-badge]' );
				if ( statusBadgeEl ) {
					statusBadgeEl.className = 'sl-hub-status-badge sl-hub-status-badge--' + ( ord.status_slug || '' );
					statusBadgeEl.textContent = ord.status_name || '';
				}

				// Customer & Delivery Info
				var custNameEl = orderModal.querySelector( '[data-sl-modal-customer-name]' );
				if ( custNameEl ) { custNameEl.textContent = ord.customer_name || 'Khách hàng'; }

				var custPhoneEl = orderModal.querySelector( '[data-sl-modal-customer-phone]' );
				if ( custPhoneEl ) { custPhoneEl.textContent = ord.customer_phone || ''; }

				var custAddrEl = orderModal.querySelector( '[data-sl-modal-shipping-address]' );
				if ( custAddrEl ) { custAddrEl.innerHTML = ord.shipping_address || 'Địa chỉ đang cập nhật'; }

				var noteWrap = orderModal.querySelector( '[data-sl-modal-note-wrap]' );
				var noteText = orderModal.querySelector( '[data-sl-modal-customer-note]' );
				if ( noteWrap && noteText ) {
					if ( ord.customer_note ) {
						noteText.textContent = ord.customer_note;
						noteWrap.style.display = 'block';
					} else {
						noteWrap.style.display = 'none';
					}
				}

				// Payment Info
				var payMethodEl = orderModal.querySelector( '[data-sl-modal-payment-method]' );
				if ( payMethodEl ) { payMethodEl.textContent = ord.payment_method || 'Thanh toán khi nhận hàng (COD)'; }

				var emailWrap = orderModal.querySelector( '[data-sl-modal-email-wrap]' );
				var emailEl = orderModal.querySelector( '[data-sl-modal-customer-email]' );
				if ( emailWrap && emailEl ) {
					if ( ord.customer_email && ord.customer_email.indexOf( 'phone.invalid' ) === -1 && ord.customer_email.indexOf( 'example.com' ) === -1 ) {
						emailEl.textContent = ord.customer_email;
						emailWrap.style.display = 'flex';
					} else {
						emailWrap.style.display = 'none';
					}
				}

				// Product Items (Chia rõ 3 cột: Sản phẩm/Đơn giá | Số lượng | Thành tiền)
				var itemsContainer = orderModal.querySelector( '[data-sl-modal-items]' );
				if ( itemsContainer && ord.items ) {
					var itemsHtml = '';
					ord.items.forEach( function ( it ) {
						itemsHtml += '<div class="sl-invoice-item-row">' +
							'<div class="sl-invoice-product-cell">' +
								'<div class="sl-invoice-item-thumb">' +
								( it.image ? '<img src="' + it.image + '" alt="' + it.name + '" loading="lazy" />' : '<div class="sl-invoice-item-thumb__placeholder">📦</div>' ) +
								'</div>' +
								'<div class="sl-invoice-item-info">' +
									'<div class="sl-invoice-item-name">' + it.name + '</div>' +
									( it.meta ? '<div class="sl-invoice-item-meta">' + it.meta + '</div>' : '' ) +
									( it.unit_price ? '<div class="sl-invoice-item-unit">Đơn giá: ' + it.unit_price + '</div>' : '' ) +
								'</div>' +
							'</div>' +
							'<div class="sl-invoice-qty-cell">' +
								'<span class="sl-invoice-qty-badge">x' + it.quantity + '</span>' +
							'</div>' +
							'<div class="sl-invoice-total-cell">' +
								'<span class="sl-invoice-item-total">' + ( it.total || it.subtotal || '' ) + '</span>' +
							'</div>' +
							'</div>';
					} );
					itemsContainer.innerHTML = itemsHtml;
				}

				// Totals
				var subtotalEl = orderModal.querySelector( '[data-sl-modal-subtotal]' );
				if ( subtotalEl ) { subtotalEl.innerHTML = ord.subtotal || ''; }

				var shippingEl = orderModal.querySelector( '[data-sl-modal-shipping]' );
				if ( shippingEl ) { shippingEl.innerHTML = ord.shipping_total || '0 đ'; }

				var discountRow = orderModal.querySelector( '[data-sl-modal-discount-row]' );
				var discountEl = orderModal.querySelector( '[data-sl-modal-discount]' );
				if ( discountRow && discountEl ) {
					if ( ord.discount_total ) {
						discountEl.innerHTML = '-' + ord.discount_total;
						discountRow.style.display = 'flex';
					} else {
						discountRow.style.display = 'none';
					}
				}

				var currentOrderId = ord.id;
				var reorderBtn = orderModal.querySelector( '[data-sl-modal-reorder]' );
				if ( reorderBtn ) {
					reorderBtn.setAttribute( 'data-order-id', currentOrderId );
					reorderBtn.disabled = false;
					reorderBtn.classList.remove( 'is-loading' );
					var reorderText = reorderBtn.querySelector( 'span' );
					if ( reorderText ) { reorderText.textContent = 'Mua lại'; }
				}
			} )
			.catch( function () {
				if ( orderModalLoading ) {
					orderModalLoading.innerHTML = '<p style="color:#ef4444;">Lỗi kết nối máy chủ.</p>';
				}
			} );
		}

		// Reorder button click event
		var modalReorderBtn = orderModal ? orderModal.querySelector( '[data-sl-modal-reorder]' ) : null;
		if ( modalReorderBtn ) {
			modalReorderBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var orderId = modalReorderBtn.getAttribute( 'data-order-id' );
				if ( ! orderId || modalReorderBtn.classList.contains( 'is-loading' ) ) {
					return;
				}

				modalReorderBtn.classList.add( 'is-loading' );
				modalReorderBtn.disabled = true;
				var btnText = modalReorderBtn.querySelector( 'span' );
				if ( btnText ) { btnText.textContent = 'Đang thêm vào giỏ...'; }

				fetch( restBase + 'reorder/' + encodeURIComponent( orderId ), {
					method: 'POST',
					headers: {
						'X-WP-Nonce': restNonce,
						'Content-Type': 'application/json'
					}
				} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( data ) {
					if ( data && data.success && data.cart_url ) {
						if ( btnText ) { btnText.textContent = 'Chuyển đến giỏ hàng...'; }
						window.location.href = data.cart_url;
					} else {
						modalReorderBtn.classList.remove( 'is-loading' );
						modalReorderBtn.disabled = false;
						if ( btnText ) { btnText.textContent = 'Mua lại'; }
						alert( ( data && data.message ) ? data.message : 'Không thể tạo lại giỏ hàng. Vui lòng thử lại.' );
					}
				} )
				.catch( function () {
					modalReorderBtn.classList.remove( 'is-loading' );
					modalReorderBtn.disabled = false;
					if ( btnText ) { btnText.textContent = 'Mua lại'; }
					alert( 'Lỗi kết nối khi mua lại đơn hàng.' );
				} );
			} );
		}

		// Click delegation for Order Card details button & cards
		document.addEventListener( 'click', function ( e ) {
			var trigger = e.target.closest( '[data-sl-order-detail]' );
			if ( trigger ) {
				e.preventDefault();
				var orderId = trigger.getAttribute( 'data-sl-order-detail' );
				openOrderModal( orderId );
				return;
			}

			// Backdrop click to close
			if ( orderModal && e.target === orderModal ) {
				closeOrderModal();
			}
		} );

		orderModalCloseBtns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				closeOrderModal();
			} );
		} );

		// ESC key to close order modal
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' || e.keyCode === 27 ) {
				if ( orderModal && orderModal.classList.contains( 'is-open' ) ) {
					closeOrderModal();
				}
			}
		} );

		// -----------------------------------------------------------------
		// Smart Address Book Operations & Modal
		// -----------------------------------------------------------------
		var addrModal = hub.querySelector( '[data-sl-address-modal]' ) || document.querySelector( '[data-sl-address-modal]' );
		var addrForm = hub.querySelector( '[data-sl-address-form]' ) || document.querySelector( '[data-sl-address-form]' );
		var addrModalOpenBtns = hub.querySelectorAll( '[data-sl-address-modal-open]' );
		var addrModalCloseBtns = addrModal ? addrModal.querySelectorAll( '[data-sl-address-modal-close]' ) : hub.querySelectorAll( '[data-sl-address-modal-close]' );
		var provinceSelect = addrForm ? addrForm.querySelector( '[data-sl-province-select]' ) : hub.querySelector( '[data-sl-province-select]' );
		var wardSelect = addrForm ? addrForm.querySelector( '[data-sl-ward-select]' ) : hub.querySelector( '[data-sl-ward-select]' );

		function openAddrModal( isEdit, data ) {
			if ( ! addrModal ) {
				return;
			}
			var titleEl = addrModal.querySelector( '[data-sl-address-modal-title]' );
			if ( titleEl ) {
				titleEl.textContent = isEdit ? 'Chỉnh sửa địa chỉ' : 'Thêm địa chỉ mới';
			}

			if ( addrForm ) {
				addrForm.reset();
				var idInput = addrForm.querySelector( '[data-sl-addr-id]' );
				if ( idInput ) {
					idInput.value = isEdit && data ? ( data.id || '' ) : '';
				}

				if ( isEdit && data ) {
					var fnInput = addrForm.querySelector( 'input[name="first_name"]' );
					var phoneInput = addrForm.querySelector( 'input[name="phone"]' );
					var addr1Input = addrForm.querySelector( 'input[name="address_1"]' );
					var defCheckbox = addrForm.querySelector( 'input[name="is_default"]' );
					var tagRadios = addrForm.querySelectorAll( 'input[name="tag"]' );

					if ( fnInput ) { fnInput.value = data.first_name || ''; }
					if ( phoneInput ) { phoneInput.value = data.phone || ''; }
					if ( addr1Input ) { addr1Input.value = data.address_1 || ''; }
					if ( defCheckbox ) { defCheckbox.checked = !! data.is_default; }

					if ( tagRadios ) {
						tagRadios.forEach( function ( radio ) {
							radio.checked = ( radio.value === ( data.tag || 'Nhà riêng' ) );
						} );
					}

					if ( provinceSelect && data.city ) {
						var pVal = String( data.city ).trim();
						var matchedCode = '';
						for ( var i = 0; i < provinceSelect.options.length; i++ ) {
							var pOpt = provinceSelect.options[i];
							if ( ! pOpt.value ) { continue; }
							if ( pOpt.value === pVal || pOpt.textContent.trim() === pVal || pOpt.textContent.trim().toLowerCase().indexOf( pVal.toLowerCase() ) !== -1 || pVal.toLowerCase().indexOf( pOpt.textContent.trim().toLowerCase() ) !== -1 ) {
								matchedCode = pOpt.value;
								break;
							}
						}
						if ( matchedCode ) {
							provinceSelect.value = matchedCode;
							loadWards( matchedCode, data.ward );
						} else {
							provinceSelect.value = pVal;
							loadWards( pVal, data.ward );
						}
					} else if ( wardSelect ) {
						wardSelect.innerHTML = '<option value="">-- Chọn Phường / Xã --</option>';
					}
				} else {
					if ( wardSelect ) {
						wardSelect.innerHTML = '<option value="">-- Chọn Phường / Xã --</option>';
					}
				}
			}

			addrModal.classList.add( 'is-open' );
			document.body.style.overflow = 'hidden';
		}

		function closeAddrModal() {
			if ( addrModal ) {
				addrModal.classList.remove( 'is-open' );
				document.body.style.overflow = '';
			}
		}

		function loadWards( pCode, selectedWard ) {
			if ( ! wardSelect ) {
				return;
			}
			wardSelect.innerHTML = '<option value="">-- Đang tải danh sách... --</option>';

			if ( ! pCode ) {
				wardSelect.innerHTML = '<option value="">-- Chọn Phường / Xã --</option>';
				return;
			}

			var url = restBase + 'address/wards/' + encodeURIComponent( pCode );

			fetch( url, {
				credentials: 'same-origin'
			} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( list ) {
				wardSelect.innerHTML = '<option value="">-- Chọn Phường / Xã --</option>';
				if ( Array.isArray( list ) ) {
					var sW = selectedWard ? String( selectedWard ).trim().toLowerCase() : '';
					list.forEach( function ( w ) {
						var opt = document.createElement( 'option' );
						opt.value = w.code || '';
						opt.textContent = w.name || '';
						if ( sW ) {
							var optVal = String( opt.value ).toLowerCase();
							var optTxt = opt.textContent.trim().toLowerCase();
							if ( optVal === sW || optTxt === sW || optTxt.indexOf( sW ) !== -1 || sW.indexOf( optTxt ) !== -1 ) {
								opt.selected = true;
							}
						}
						wardSelect.appendChild( opt );
					} );
				}
			} )
			.catch( function () {
				wardSelect.innerHTML = '<option value="">-- Chọn Phường / Xã --</option>';
			} );
		}

		if ( provinceSelect ) {
			provinceSelect.addEventListener( 'change', function () {
				loadWards( this.value, null );
			} );
		}

		addrModalOpenBtns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				openAddrModal( false, null );
			} );
		} );

		addrModalCloseBtns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				closeAddrModal();
			} );
		} );

		// Address Form Submit Handler
		if ( addrForm ) {
			addrForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var submitBtn = addrForm.querySelector( 'button[type="submit"]' );
				var originalText = submitBtn ? submitBtn.textContent : '';
				if ( submitBtn ) {
					submitBtn.disabled = true;
					submitBtn.textContent = 'Đang lưu...';
				}

				var formData = new FormData( addrForm );
				var payload = {};
				formData.forEach( function ( v, k ) { payload[k] = v; } );

				fetch( restBase + 'addresses', {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': restNonce
					},
					body: JSON.stringify( payload )
				} )
				.then( function ( res ) {
					return res.json().then( function ( data ) {
						return { ok: res.ok, status: res.status, data: data };
					} );
				} )
				.then( function ( result ) {
					if ( ! result.ok || ! result.data || result.data.success !== true ) {
						var msg = ( result.data && result.data.message ) ? result.data.message : 'Có lỗi xảy ra khi lưu địa chỉ. Vui lòng thử lại.';
						alert( msg );
						if ( submitBtn ) {
							submitBtn.disabled = false;
							submitBtn.textContent = originalText;
						}
						return;
					}

					closeAddrModal();
					window.location.hash = '#address';
					window.location.reload();
				} )
				.catch( function ( err ) {
					alert( 'Không thể kết nối máy chủ. Vui lòng thử lại.' );
					if ( submitBtn ) {
						submitBtn.disabled = false;
						submitBtn.textContent = originalText;
					}
				} );
			} );
		}

		// Address Actions Click Delegation (Delete, Set Default, Edit)
		document.addEventListener( 'click', function ( e ) {
			var deleteBtn = e.target.closest( '[data-sl-address-delete]' );
			if ( deleteBtn ) {
				e.preventDefault();
				var id = deleteBtn.getAttribute( 'data-sl-address-delete' );
				if ( confirm( 'Bạn có chắc chắn muốn xóa địa chỉ này?' ) ) {
					fetch( restBase + 'addresses?id=' + encodeURIComponent( id ), {
						method: 'DELETE',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': restNonce }
					} )
					.then( function () {
						window.location.hash = '#address';
						window.location.reload();
					} )
					.catch( function () {
						window.location.hash = '#address';
						window.location.reload();
					} );
				}
				return;
			}

			var defaultBtn = e.target.closest( '[data-sl-address-set-default]' );
			if ( defaultBtn ) {
				e.preventDefault();
				var idDef = defaultBtn.getAttribute( 'data-sl-address-set-default' );
				fetch( restBase + 'addresses/set-default', {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': restNonce
					},
					body: JSON.stringify( { id: idDef } )
				} )
				.then( function () {
					window.location.hash = '#address';
					window.location.reload();
				} )
				.catch( function () {
					window.location.hash = '#address';
					window.location.reload();
				} );
				return;
			}

			var editBtn = e.target.closest( '[data-sl-address-edit]' );
			if ( editBtn ) {
				e.preventDefault();
				var rawData = editBtn.getAttribute( 'data-sl-address-edit' );
				try {
					var parsedData = JSON.parse( rawData );
					openAddrModal( true, parsedData );
				} catch ( err ) {}
			}
		} );

		// Checkout Quick-Picker Dropdown Sync (if present on checkout page)
		var checkoutSelect = document.getElementById( 'sl-checkout-address-select' );
		if ( checkoutSelect ) {
			checkoutSelect.addEventListener( 'change', function () {
				try {
					var item = JSON.parse( this.value );
					var fieldsMap = {
						'shipping_first_name': item.first_name,
						'shipping_last_name': item.last_name,
						'shipping_phone': item.phone,
						'shipping_address_1': item.address_1,
						'shipping_city': item.ward,
						'shipping_state': item.city
					};

					Object.keys( fieldsMap ).forEach( function ( fieldId ) {
						var inputEl = document.getElementById( fieldId );
						if ( inputEl && fieldsMap[fieldId] !== undefined ) {
							inputEl.value = fieldsMap[fieldId];
							var evt = document.createEvent( 'HTMLEvents' );
							evt.initEvent( 'change', true, false );
							inputEl.dispatchEvent( evt );
						}
					} );
				} catch ( err ) {}
			} );
		}

		// -----------------------------------------------------------------
		// Voucher Interactivity: Copy, Detail Modal, Apply to Cart
		// -----------------------------------------------------------------
		var voucherModal = document.querySelector( '[data-sl-voucher-modal]' );
		var voucherModalCloseButtons = voucherModal ? voucherModal.querySelectorAll( '[data-sl-voucher-modal-close]' ) : [];
		var modalCodeEl = voucherModal ? voucherModal.querySelector( '[data-sl-modal-code]' ) : null;
		var modalHeadlineEl = voucherModal ? voucherModal.querySelector( '[data-sl-modal-headline]' ) : null;
		var modalExpiryEl = voucherModal ? voucherModal.querySelector( '[data-sl-modal-expiry]' ) : null;
		var modalBadgeEl = voucherModal ? voucherModal.querySelector( '[data-sl-modal-status-badge]' ) : null;
		var modalTermsEl = voucherModal ? voucherModal.querySelector( '[data-sl-modal-terms]' ) : null;
		var modalCopyBtn = voucherModal ? voucherModal.querySelector( '[data-sl-modal-copy]' ) : null;
		var modalApplyBtn = voucherModal ? voucherModal.querySelector( '[data-sl-modal-apply]' ) : null;

		function showToast( message, type ) {
			type = type || 'success';
			var container = document.getElementById( 'sl-toast-container' );
			if ( ! container ) {
				container = document.createElement( 'div' );
				container.id = 'sl-toast-container';
				container.className = 'sl-toast-container';
				document.body.appendChild( container );
			}
			var toast = document.createElement( 'div' );
			toast.className = 'sl-toast sl-toast--' + type;
			toast.innerHTML = '<span class="sl-toast__icon">✓</span>' +
				'<span class="sl-toast__msg">' + message + '</span>' +
				'<button type="button" class="sl-toast__close" aria-label="Đóng">✕</button>';
			container.appendChild( toast );
			setTimeout( function () { toast.classList.add( 'sl-toast--visible' ); }, 30 );
			var timer = setTimeout( function () { dismissToast( toast ); }, 3500 );
			var closeBtn = toast.querySelector( '.sl-toast__close' );
			if ( closeBtn ) {
				closeBtn.addEventListener( 'click', function () {
					clearTimeout( timer );
					dismissToast( toast );
				} );
			}
		}

		function dismissToast( toast ) {
			if ( ! toast ) return;
			toast.classList.remove( 'sl-toast--visible' );
			setTimeout( function () {
				if ( toast.parentNode ) toast.parentNode.removeChild( toast );
			}, 300 );
		}

		function copyToClipboard( text, triggerBtn ) {
			if ( ! text ) {
				return;
			}

			function showSuccessFeedback() {
				if ( ! triggerBtn ) {
					return;
				}
				var originalHtml = triggerBtn.innerHTML;
				triggerBtn.classList.add( 'is-copied' );
				triggerBtn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg> <span>Đã chép!</span>';
				showToast( 'Đã sao chép mã ' + text + ' thành công!' );
				setTimeout( function () {
					triggerBtn.classList.remove( 'is-copied' );
					triggerBtn.innerHTML = originalHtml;
				}, 2000 );
			}

			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then( showSuccessFeedback ).catch( function () {
					fallbackCopyText( text, showSuccessFeedback );
				} );
			} else {
				fallbackCopyText( text, showSuccessFeedback );
			}
		}

		function fallbackCopyText( text, cb ) {
			var textArea = document.createElement( 'textarea' );
			textArea.value = text;
			textArea.style.position = 'fixed';
			textArea.style.left = '-999999px';
			textArea.style.top = '-999999px';
			document.body.appendChild( textArea );
			textArea.focus();
			textArea.select();
			try {
				document.execCommand( 'copy' );
				if ( typeof cb === 'function' ) {
					cb();
				}
			} catch ( err ) {}
			document.body.removeChild( textArea );
		}

		// Event Delegation for Voucher Copy Buttons
		document.addEventListener( 'click', function ( e ) {
			var copyBtn = e.target.closest( '[data-sl-voucher-copy]' );
			if ( copyBtn ) {
				e.preventDefault();
				var code = copyBtn.getAttribute( 'data-code' );
				copyToClipboard( code, copyBtn );
			}
		} );

		// Modal Copy Button
		if ( modalCopyBtn ) {
			modalCopyBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var code = modalCopyBtn.getAttribute( 'data-code' );
				copyToClipboard( code, modalCopyBtn );
			} );
		}

		// Open Voucher Details Modal
		document.addEventListener( 'click', function ( e ) {
			var detailBtn = e.target.closest( '[data-sl-voucher-detail]' );
			if ( ! detailBtn || ! voucherModal ) {
				return;
			}
			e.preventDefault();

			var rawData = detailBtn.getAttribute( 'data-voucher' );
			if ( ! rawData ) {
				return;
			}

			try {
				var v = JSON.parse( rawData );
				if ( modalCodeEl ) {
					modalCodeEl.textContent = v.code || '---';
				}
				if ( modalCopyBtn ) {
					modalCopyBtn.setAttribute( 'data-code', v.code || '' );
				}
				if ( modalApplyBtn ) {
					modalApplyBtn.setAttribute( 'data-code', v.code || '' );
					if ( v.status !== 'active' ) {
						modalApplyBtn.disabled = true;
						modalApplyBtn.textContent = 'Mã không khả dụng';
					} else {
						modalApplyBtn.disabled = false;
						modalApplyBtn.textContent = 'Dùng mã ngay';
					}
				}
				if ( modalHeadlineEl ) {
					modalHeadlineEl.textContent = v.headline || '';
				}
				if ( modalExpiryEl ) {
					modalExpiryEl.textContent = 'HSD: ' + ( v.expiry_text || 'Không giới hạn' );
				}
				if ( modalBadgeEl ) {
					modalBadgeEl.className = 'sl-voucher-badge';
					if ( v.status === 'used' ) {
						modalBadgeEl.classList.add( 'sl-voucher-badge--used' );
						modalBadgeEl.textContent = 'Đã sử dụng';
						modalBadgeEl.style.display = 'inline-block';
					} else if ( v.status === 'expired' ) {
						modalBadgeEl.classList.add( 'sl-voucher-badge--expired' );
						modalBadgeEl.textContent = 'Hết hạn';
						modalBadgeEl.style.display = 'inline-block';
					} else if ( v.is_expiring_soon ) {
						modalBadgeEl.classList.add( 'sl-voucher-badge--warning' );
						modalBadgeEl.textContent = 'Sắp hết hạn';
						modalBadgeEl.style.display = 'inline-block';
					} else {
						modalBadgeEl.style.display = 'none';
					}
				}

				if ( modalTermsEl ) {
					modalTermsEl.innerHTML = '';
					if ( Array.isArray( v.terms ) && v.terms.length > 0 ) {
						v.terms.forEach( function ( t ) {
							var row = document.createElement( 'div' );
							row.className = 'sl-voucher-term-item';
							row.innerHTML = '<span class="sl-voucher-term-label">' + escapeHtml( t.label ) + '</span>' +
								'<span class="sl-voucher-term-val">' + escapeHtml( t.value ) + '</span>';
							modalTermsEl.appendChild( row );
						} );
					}
				}

				voucherModal.classList.add( 'is-open' );
				document.body.style.overflow = 'hidden';
			} catch ( err ) {}
		} );

		function closeVoucherModal() {
			if ( voucherModal ) {
				voucherModal.classList.remove( 'is-open' );
				document.body.style.overflow = '';
			}
		}

		voucherModalCloseButtons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				closeVoucherModal();
			} );
		} );

		if ( voucherModal ) {
			voucherModal.addEventListener( 'click', function ( e ) {
				if ( e.target === voucherModal ) {
					closeVoucherModal();
				}
			} );
		}

		function escapeHtml( str ) {
			if ( ! str ) return '';
			var div = document.createElement( 'div' );
			div.textContent = str;
			return div.innerHTML;
		}

		// Apply Voucher to Cart
		function handleApplyVoucher( code, triggerBtn ) {
			if ( ! code ) {
				return;
			}

			if ( triggerBtn ) {
				triggerBtn.disabled = true;
				triggerBtn.classList.add( 'is-loading' );
			}

			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', restBase + 'apply-voucher', true );
			xhr.setRequestHeader( 'Content-Type', 'application/json;charset=UTF-8' );
			if ( restNonce ) {
				xhr.setRequestHeader( 'X-WP-Nonce', restNonce );
			}

			xhr.onreadystatechange = function () {
				if ( xhr.readyState === 4 ) {
					if ( triggerBtn ) {
						triggerBtn.disabled = false;
						triggerBtn.classList.remove( 'is-loading' );
					}

					try {
						var res = JSON.parse( xhr.responseText );
						if ( xhr.status === 200 && res.success ) {
							alert( res.message || 'Áp dụng mã thành công!' );
							if ( res.redirect_url ) {
								window.location.href = res.redirect_url;
							}
						} else {
							alert( res.message || 'Không thể áp dụng mã này vào giỏ hàng.' );
						}
					} catch ( err ) {
						alert( 'Có lỗi xảy ra khi áp dụng mã giảm giá.' );
					}
				}
			};

			xhr.send( JSON.stringify( { code: code } ) );
		}

		document.addEventListener( 'click', function ( e ) {
			var applyBtn = e.target.closest( '[data-sl-voucher-apply]' );
			if ( applyBtn ) {
				e.preventDefault();
				var code = applyBtn.getAttribute( 'data-code' );
				handleApplyVoucher( code, applyBtn );
			}
		} );

		if ( modalApplyBtn ) {
			modalApplyBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var code = modalApplyBtn.getAttribute( 'data-code' );
				handleApplyVoucher( code, modalApplyBtn );
			} );
		}

		// -----------------------------------------------------------------
		// Logout Modal Confirmation
		// -----------------------------------------------------------------
		function openLogoutModal() {
			if ( logoutModal ) {
				logoutModal.classList.add( 'is-open' );
				document.body.style.overflow = 'hidden';
			}
		}

		function closeLogoutModal() {
			if ( logoutModal ) {
				logoutModal.classList.remove( 'is-open' );
				document.body.style.overflow = '';
			}
		}

		logoutTriggers.forEach( function ( trigger ) {
			trigger.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				openLogoutModal();
			} );
		} );

		if ( logoutCancel ) {
			logoutCancel.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				closeLogoutModal();
			} );
		}

		if ( logoutModal ) {
			logoutModal.addEventListener( 'click', function ( e ) {
				if ( e.target === logoutModal ) {
					closeLogoutModal();
				}
			} );
		}

		// -----------------------------------------------------------------
		// Profile & Security Form Reset (Huỷ button)
		// -----------------------------------------------------------------
		hub.querySelectorAll( '.sl-hub-profile-actions button[type="reset"]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var form = btn.closest( 'form' );
				if ( form ) {
					form.reset();
				}
			} );
		} );
	} );
} )();
