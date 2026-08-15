/**
 * OmniWP Slide Cart & Cart In-page Controller
 */
(function ($) {
	'use strict';

	var config = window.omniwpCartConfig || {};

	/**
	 * Debounce utility: delays fn execution until `wait` ms after the last call.
	 * Returns a wrapper that also exposes .cancel() to abort a pending timer.
	 */
	function debounce(fn, wait) {
		var timer = null;
		var wrapper = function () {
			var ctx = this, args = arguments;
			clearTimeout(timer);
			timer = setTimeout(function () {
				timer = null;
				fn.apply(ctx, args);
			}, wait);
		};
		wrapper.cancel = function () { clearTimeout(timer); timer = null; };
		return wrapper;
	}

	/**
	 * Lightweight toast notification with optional Undo action.
	 */
	function showToast(message, type, undoCallback) {
		type = type || 'success';
		var $existing = $('.sl-toast-notification');
		$existing.remove();

		var iconSvg = type === 'success'
			? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>'
			: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>';

		// Escape message to prevent XSS injection from server data (e.g. product names).
		var safeMessage = $('<span/>').text(message).html();

		var undoHtml = '';
		if (typeof undoCallback === 'function') {
			undoHtml = '<button type="button" class="sl-toast__undo-btn">' + (config.i18n.undo || 'Hoàn tác') + '</button>';
		}

		var $toast = $('<div class="sl-toast-notification sl-toast--' + type + (undoHtml ? ' sl-toast--with-undo' : '') + '">' +
			'<span class="sl-toast__icon">' + iconSvg + '</span>' +
			'<span class="sl-toast__text">' + safeMessage + '</span>' +
			undoHtml +
			'</div>');

		var dismissed = false;

		if (undoHtml) {
			$toast.on('click', '.sl-toast__undo-btn', function (e) {
				e.preventDefault();
				if (dismissed) return;
				dismissed = true;
				clearTimeout(autoTimer);
				$toast.removeClass('is-visible');
				setTimeout(function () { $toast.remove(); }, 200);
				undoCallback();
			});
		}

		$('body').append($toast);
		setTimeout(function () { $toast.addClass('is-visible'); }, 20);

		var displayDuration = undoHtml ? 5000 : 2800;
		var autoTimer = setTimeout(function () {
			dismissed = true;
			$toast.removeClass('is-visible');
			setTimeout(function () { $toast.remove(); }, 300);
		}, displayDuration);
	}

	var SlideCart = {
		init: function () {
			this.cacheDOM();
			this.bindEvents();
			this.initStickyCheckoutObserver();
		},

		cacheDOM: function () {
			this.$drawer    = $('#sl-slide-cart');
			this.$overlay   = $('#sl-slide-cart-overlay');
			this.$closeBtn  = $('#sl-slide-cart-close, .sl-slide-cart-close-btn');
			this.$floating  = $('#sl-floating-cart');
			this.$body      = $('#sl-slide-cart-body');
			this.$footer    = $('#sl-slide-cart-footer');
		},

		bindEvents: function () {
			var self = this;

			// Open trigger
			$(document).on('click', '#sl-floating-cart, [data-omniwp="cart"], .sl-cart-trigger', function (e) {
				e.preventDefault();
				self.open();
			});

			// Intercept theme cart links if desired
			$(document).on('click', 'a[href*="/cart/"], a[href$="/cart"]', function (e) {
				if (!$(this).closest('.sl-cart-actions, .sl-cart-page-wrap').length) {
					if (self.$drawer.length) {
						e.preventDefault();
						self.open();
					}
				}
			});

			// Close triggers
			$(document).on('click', '#sl-slide-cart-close, .sl-slide-cart-close-btn', function (e) {
				e.preventDefault();
				self.close();
			});

			this.$overlay.on('click', function (e) {
				e.preventDefault();
				self.close();
			});

			$(document).on('keydown', function (e) {
				if (e.key === 'Escape' && self.$drawer.hasClass('is-open')) {
					self.close();
				}
			});

			// WooCommerce added_to_cart event
			$(document.body).on('added_to_cart', function (event, fragments, cart_hash, $button) {
				self.refresh(function () {
					if (config.autoOpen) {
						self.open();
					}
				});
			});

			// Debounced quantity update — prevents race conditions from rapid clicks.
			var debouncedQtyUpdate = debounce(function (key, qty) {
				self.updateQuantity(key, qty);
			}, 350);

			// Quantity steppers (Slide Cart & In-page Cart)
			$(document).on('click', '.sl-qty-plus', function (e) {
				e.preventDefault();
				var $row   = $(this).closest('[data-key]');
				var key    = $row.data('key');
				var $input = $row.find('.sl-qty-input');
				var current = parseInt($input.val(), 10) || 1;
				var max     = parseInt($input.attr('max'), 10) || 999;
				if (current < max) {
					$input.val(current + 1);
					debouncedQtyUpdate(key, current + 1);
				}
			});

			$(document).on('click', '.sl-qty-minus', function (e) {
				e.preventDefault();
				var $row   = $(this).closest('[data-key]');
				var key    = $row.data('key');
				var $input = $row.find('.sl-qty-input');
				var current = parseInt($input.val(), 10) || 1;
				if (current > 1) {
					$input.val(current - 1);
					debouncedQtyUpdate(key, current - 1);
				} else if (current === 1) {
					debouncedQtyUpdate.cancel();
					self.removeItem(key);
				}
			});

			// Cross-sell qty stepper buttons
			$(document).on('click', '.sl-qty-btn--cross-plus', function (e) {
				e.preventDefault();
				var $input  = $(this).siblings('.sl-cross-qty-input');
				var current = parseInt($input.val(), 10) || 1;
				var max     = parseInt($input.attr('max'), 10) || 99;
				if (current < max) { $input.val(current + 1); }
			});

			$(document).on('click', '.sl-qty-btn--cross-minus', function (e) {
				e.preventDefault();
				var $input  = $(this).siblings('.sl-cross-qty-input');
				var current = parseInt($input.val(), 10) || 1;
				if (current > 1) { $input.val(current - 1); }
			});

			// Change variation
			$(document).on('change', '.sl-cart-attr-select', function (e) {
				e.preventDefault();
				var $select    = $(this);
				var $container = $select.closest('[data-key]');
				var key        = $container.data('key');
				var attributes = {};

				$container.find('.sl-cart-attr-select').each(function () {
					var attrName = $(this).data('attribute');
					var attrVal  = $(this).val();
					if (attrName) {
						attributes[attrName] = attrVal;
					}
				});

				self.changeVariation(key, attributes);
			});

			// Remove item
			$(document).on('click', '.sl-cart-item__remove', function (e) {
				e.preventDefault();
				var $row = $(this).closest('[data-key]');
				var key  = $row.data('key');
				self.removeItem(key);
			});

			// Apply Coupon (Slide Cart)
			$(document).on('submit', '#sl-cart-coupon-form', function (e) {
				e.preventDefault();
				var $form = $(this);
				var code  = $form.find('input[type="text"]').val().trim();
				if (!code) return;

				var $btn = $form.find('button[type="submit"]');
				$btn.prop('disabled', true).text('...');

				self.applyCoupon(code, $('#sl-coupon-message'), function () {
					$btn.prop('disabled', false).text(config.i18n.applyCoupon || 'Áp dụng');
				});
			});

			// Apply Coupon (In-page Cart)
			$(document).on('submit', '#sl-inpage-coupon-form', function (e) {
				e.preventDefault();
				var $form = $(this);
				var code = $.trim($form.find('#sl-inpage-coupon-code').val());
				if (!code) return;

				var $btn = $form.find('button[type="submit"]');
				$btn.prop('disabled', true).text('...');

				self.applyCoupon(code, $('#sl-inpage-coupon-msg'), function () {
					$btn.prop('disabled', false).text(config.i18n.applyCoupon || 'Áp dụng');
				});
			});

			// Sub-slide Voucher Panel form submit
			$(document).on('submit', '#sl-voucher-panel-form', function (e) {
				e.preventDefault();
				var $form = $(this);
				var code = $.trim($form.find('#sl-voucher-panel-code').val());
				if (!code) return;

				var $btn = $form.find('button[type="submit"]');
				$btn.prop('disabled', true).text('...');

				self.applyCoupon(code, $('#sl-voucher-panel-msg'), function () {
					$btn.prop('disabled', false).text(config.i18n.applyCoupon || 'Áp dụng');
				});
			});

			// 1-Click Apply from Voucher Card
			$(document).on('click', '.sl-voucher-apply-btn:not(:disabled)', function (e) {
				e.preventDefault();
				var code = $(this).data('code');
				if (!code) return;

				var $btn = $(this);
				$btn.prop('disabled', true).text('...');

				self.applyCoupon(code, $('#sl-voucher-panel-msg, #sl-inpage-coupon-msg'), function () {
					$btn.prop('disabled', false).text('Dùng mã');
				});
			});

			// Accordion Toggle (Slide Cart & In-page Cart)
			$(document).on('click', '.sl-coupon-accordion__toggle', function (e) {
				e.preventDefault();
				var $toggle = $(this);
				var $content = $toggle.next('.sl-coupon-accordion__content');
				var isOpen = $toggle.hasClass('is-open');
				$toggle.toggleClass('is-open', !isOpen).attr('aria-expanded', !isOpen);
				$content.stop(true, true).slideToggle(200);
			});

			// Open Voucher Drawer Sub-slide Panel (Slide Cart)
			$(document).on('click', '#sl-open-voucher-drawer-btn', function (e) {
				e.preventDefault();
				$('#sl-slide-cart').addClass('is-voucher-panel-open');
			});

			// Close Voucher Drawer Sub-slide Panel (Slide Cart)
			$(document).on('click', '#sl-voucher-panel-back, #sl-voucher-panel-done', function (e) {
				e.preventDefault();
				$('#sl-slide-cart').removeClass('is-voucher-panel-open');
			});

			$(document).on('click', '#sl-voucher-panel-close', function (e) {
				e.preventDefault();
				$('#sl-slide-cart').removeClass('is-voucher-panel-open');
				self.close();
			});

			// Open In-Page Voucher Modal (Cart Page)
			$(document).on('click', '#sl-inpage-open-voucher-modal-btn', function (e) {
				e.preventDefault();
				$('#sl-inpage-voucher-modal').addClass('is-open').attr('aria-hidden', 'false');
			});

			// Close In-Page Voucher Modal (Cart Page)
			$(document).on('click', '#sl-inpage-voucher-modal-close, #sl-inpage-voucher-modal-done, #sl-inpage-voucher-modal-backdrop', function (e) {
				e.preventDefault();
				$('#sl-inpage-voucher-modal').removeClass('is-open').attr('aria-hidden', 'true');
			});

			// Kho Voucher Module: 1-Touch Filter Tabs
			$(document).on('click', '.sl-voucher-tab', function (e) {
				e.preventDefault();
				var $tab    = $(this);
				var filter  = $tab.data('filter');
				var $module = $tab.closest('.sl-voucher-module');

				$module.find('.sl-voucher-tab').removeClass('is-active').attr('aria-selected', 'false');
				$tab.addClass('is-active').attr('aria-selected', 'true');

				var $tickets = $module.find('.sl-coupon-ticket');
				if ('all' === filter) {
					$tickets.show();
				} else if ('freeship' === filter) {
					$tickets.hide().filter('[data-type="freeship"]').show();
				} else if ('discount' === filter) {
					$tickets.hide().filter('[data-type="discount"]').show();
				} else if ('mine' === filter) {
					$tickets.hide().filter('.is-applied, :not(.is-disabled)').show();
				} else if ('expired' === filter) {
					$tickets.hide().filter('.is-expired').show();
				}
			});

			// Kho Voucher Module: Manual Form Submit
			$(document).on('submit', '.sl-voucher-module-form', function (e) {
				e.preventDefault();
				var $form = $(this);
				var $input = $form.find('.sl-voucher-module-code-input');
				var code = $.trim($input.val());
				if (!code) return;

				var $btn = $form.find('button[type="submit"]');
				var $msg = $form.siblings('.sl-voucher-module-msg');
				$btn.prop('disabled', true).text('...');

				self.applyCoupon(code, $msg, function () {
					$btn.prop('disabled', false).text('Áp dụng');
				});
			});

			// Kho Voucher Module: Copy Code to Clipboard
			$(document).on('click', '.sl-voucher-copy-btn', function (e) {
				e.preventDefault();
				var code = $(this).data('code');
				if (!code) return;

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(code).then(function () {
						showToast('Đã sao chép mã ' + code + '!');
					});
				} else {
					var $temp = $('<input>');
					$('body').append($temp);
					$temp.val(code).select();
					document.execCommand('copy');
					$temp.remove();
					showToast('Đã sao chép mã ' + code + '!');
				}
			});

			// Kho Voucher Module: Quick Win Cross-Sell Trigger
			$(document).on('click', '.sl-voucher-quickwin-btn', function (e) {
				e.preventDefault();
				// Close voucher drawer/modal to return to cart body cross-sells
				$('#sl-slide-cart').removeClass('is-voucher-panel-open');
				$('#sl-inpage-voucher-modal').removeClass('is-open').attr('aria-hidden', 'true');
				showToast('Thêm sản phẩm gợi ý bên dưới để đạt mốc mở khóa mã!', 'info');

				var $cross = $('.sl-cart-cross-sells, .sl-cart-cross-sells-card');
				if ($cross.length) {
					$cross[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
					$cross.addClass('sl-pulse-highlight');
					setTimeout(function () { $cross.removeClass('sl-pulse-highlight'); }, 1500);
				}
			});

			// In-Page Modal Coupon Form Submit
			$(document).on('submit', '#sl-inpage-modal-coupon-form', function (e) {
				e.preventDefault();
				var $form = $(this);
				var code = $.trim($form.find('#sl-inpage-modal-coupon-code').val());
				if (!code) return;

				var $btn = $form.find('button[type="submit"]');
				$btn.prop('disabled', true).text('...');

				self.applyCoupon(code, $('#sl-inpage-modal-coupon-msg'), function () {
					$btn.prop('disabled', false).text(config.i18n.applyCoupon || 'Áp dụng');
				});
			});

			// Remove coupon
			$(document).on('click', '.sl-coupon-tag__remove', function (e) {
				e.preventDefault();
				var code = $(this).data('code');
				$.ajax({
					url: config.ajaxUrl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'omniwp_remove_coupon',
						nonce: config.nonce,
						coupon_code: code
					},
					success: function (res) {
						if (res.success) {
							self.renderCart(res.data.cart);
						}
					}
				});
			});

			// Cross-sell Quick Add to Cart
			$(document).on('click', '.sl-cross-item-btn, .sl-cross-card__btn', function (e) {
				e.preventDefault();
				var productId = $(this).data('product_id');
				if (!productId) return;
				var $btn  = $(this);
				var $card = $btn.closest('.sl-cross-card, .sl-cross-item-card');
				var qty   = parseInt($card.find('.sl-cross-qty-input').val(), 10) || 1;
				var btnLabel = config.i18n.addToCart || 'Thêm';
				$btn.prop('disabled', true).text('...');
				$.ajax({
					url: config.ajaxUrl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'omniwp_add_to_cart',
						nonce: config.nonce,
						product_id: productId,
						quantity: qty
					},
					success: function (res) {
						$btn.prop('disabled', false).text(btnLabel);
						if (res.success) {
							self.renderCart(res.data.cart || res.data);
							showToast(config.i18n.updateSuccess || 'Đã thêm vào giỏ hàng');
						} else {
							showToast((res.data && res.data.message) || config.i18n.error || 'Có lỗi xảy ra', 'error');
						}
					},
					error: function () {
						$btn.prop('disabled', false).text(btnLabel);
						showToast(config.i18n.error || 'Có lỗi xảy ra', 'error');
					}
				});
			});

			// Smart Gap-Filler Addon 1-Click Quick Add
			$(document).on('click', '.sl-gap-filler-add-btn', function (e) {
				e.preventDefault();
				var productId = $(this).data('product_id');
				if (!productId) return;
				var $btn = $(this);
				$btn.prop('disabled', true).text('...');
				$.ajax({
					url: config.ajaxUrl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'omniwp_add_to_cart',
						nonce: config.nonce,
						product_id: productId,
						quantity: 1
					},
					success: function (res) {
						$btn.prop('disabled', false).text('+ ' + (config.i18n.add || 'Thêm'));
						if (res.success) {
							self.renderCart(res.data.cart || res.data);
							showToast(config.i18n.updateSuccess || 'Đã thêm vào giỏ hàng');
						} else {
							showToast((res.data && res.data.message) || config.i18n.error || 'Có lỗi xảy ra', 'error');
						}
					},
					error: function () {
						$btn.prop('disabled', false).text('+ ' + (config.i18n.add || 'Thêm'));
						showToast(config.i18n.error || 'Có lỗi xảy ra', 'error');
					}
				});
			});
		},

		applyCoupon: function (code, $msgBox, callback) {
			var self = this;
			$.ajax({
				url: config.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'omniwp_apply_coupon',
					nonce: config.nonce,
					coupon_code: code
				},
				success: function (res) {
					if (typeof callback === 'function') callback(res);
					if (res.success) {
						// Auto close Voucher sub-panel and focus on Main Slide Cart
						$('#sl-slide-cart').removeClass('is-voucher-panel-open');
						$('#sl-coupon-collapse').slideDown(200);
						$('#sl-coupon-toggle').addClass('is-open');
						$('#sl-cart-coupon-code, #sl-voucher-panel-code, #sl-inpage-coupon-code').val('');

						$('#sl-coupon-message, #sl-inpage-coupon-msg').removeClass('is-error').addClass('is-success').text(res.data.message).show();
						self.renderCart(res.data.cart);
					} else {
						if ($msgBox && $msgBox.length) {
							$msgBox.removeClass('is-success').addClass('is-error').text(res.data.message).show();
						}
					}
				},
				error: function () {
					if (typeof callback === 'function') callback();
				}
			});
		},

		open: function () {
			this.$overlay.addClass('is-active').attr('aria-hidden', 'false');
			this.$drawer.removeClass('is-voucher-panel-open').addClass('is-open').attr('aria-hidden', 'false');
			$('body').css('overflow', 'hidden');
		},

		close: function () {
			this.$overlay.removeClass('is-active').attr('aria-hidden', 'true');
			this.$drawer.removeClass('is-open is-voucher-panel-open').attr('aria-hidden', 'true');
			$('body').css('overflow', '');
		},

		refresh: function (callback) {
			var self = this;
			$.ajax({
				url: config.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'omniwp_get_cart',
					nonce: config.nonce
				},
				success: function (res) {
					if (res.success) {
						self.renderCart(res.data);
						if (typeof callback === 'function') {
							callback(res.data);
						}
					}
				}
			});
		},

		updateQuantity: function (key, qty) {
			var self = this;
			self.$drawer.addClass('is-loading');
			$('.sl-cart-page-wrap').addClass('is-loading');
			$.ajax({
				url: config.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'omniwp_update_cart_qty',
					nonce: config.nonce,
					cart_item_key: key,
					key: key,
					quantity: qty
				},
				success: function (res) {
					self.$drawer.removeClass('is-loading');
					$('.sl-cart-page-wrap').removeClass('is-loading');
					if (res.success) {
						self.renderCart(res.data);
						showToast(config.i18n.updateSuccess || 'Đã cập nhật giỏ hàng');
					}
				},
				error: function () {
					self.$drawer.removeClass('is-loading');
					$('.sl-cart-page-wrap').removeClass('is-loading');
					showToast(config.i18n.error || 'Có lỗi xảy ra', 'error');
				}
			});
		},

		changeVariation: function (key, attributes) {
			var self = this;
			self.$drawer.addClass('is-loading');
			$('.sl-cart-page-wrap').addClass('is-loading');

			$.ajax({
				url: config.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'omniwp_change_cart_variation',
					nonce: config.nonce,
					cart_item_key: key,
					key: key,
					attributes: attributes
				},
				success: function (res) {
					self.$drawer.removeClass('is-loading');
					$('.sl-cart-page-wrap').removeClass('is-loading');
					if (res.success) {
						self.renderCart(res.data);
						showToast(config.i18n.updateSuccess || 'Đã cập nhật giỏ hàng');
					} else {
						showToast((res.data && res.data.message) || config.i18n.error || 'Có lỗi xảy ra', 'error');
					}
				},
				error: function () {
					self.$drawer.removeClass('is-loading');
					$('.sl-cart-page-wrap').removeClass('is-loading');
					showToast(config.i18n.error || 'Có lỗi xảy ra', 'error');
				}
			});
		},

		removeItem: function (key) {
			var self = this;
			self.$drawer.addClass('is-loading');
			$('.sl-cart-page-wrap').addClass('is-loading');
			$.ajax({
				url: config.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'omniwp_remove_cart_item',
					nonce: config.nonce,
					cart_item_key: key,
					key: key
				},
				success: function (res) {
					self.$drawer.removeClass('is-loading');
					$('.sl-cart-page-wrap').removeClass('is-loading');
					if (res.success) {
						var cartData    = res.data.cart || res.data;
						var removedItem = res.data.removed_item;
						self.renderCart(cartData);

						var msg = (removedItem && removedItem.name)
							? ('Đã xóa "' + removedItem.name + '"')
							: (config.i18n.removeSuccess || 'Đã xóa sản phẩm khỏi giỏ');

						if (removedItem && removedItem.product_id) {
							showToast(msg, 'success', function () {
								self.restoreItem(removedItem);
							});
						} else {
							showToast(msg, 'success');
						}
					}
				},
				error: function () {
					self.$drawer.removeClass('is-loading');
					$('.sl-cart-page-wrap').removeClass('is-loading');
					showToast(config.i18n.error || 'Có lỗi xảy ra', 'error');
				}
			});
		},

		restoreItem: function (item) {
			var self = this;
			self.$drawer.addClass('is-loading');
			$('.sl-cart-page-wrap').addClass('is-loading');
			$.ajax({
				url: config.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'omniwp_restore_cart_item',
					nonce: config.nonce,
					product_id: item.product_id,
					variation_id: item.variation_id || 0,
					quantity: item.quantity || 1,
					variation: item.variation || {}
				},
				success: function (res) {
					self.$drawer.removeClass('is-loading');
					$('.sl-cart-page-wrap').removeClass('is-loading');
					if (res.success) {
						self.renderCart(res.data.cart || res.data);
						showToast(res.data.message || 'Đã khôi phục sản phẩm vào giỏ hàng!', 'success');
					} else {
						showToast((res.data && res.data.message) || config.i18n.error || 'Không thể khôi phục', 'error');
					}
				},
				error: function () {
					self.$drawer.removeClass('is-loading');
					$('.sl-cart-page-wrap').removeClass('is-loading');
					showToast(config.i18n.error || 'Có lỗi xảy ra', 'error');
				}
			});
		},

		renderCart: function (cart) {
			if (!cart) return;

			// Header & floating count
			var titleHtml = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg> ' +
				(config.i18n.cartTitle || 'Giỏ hàng') +
				(cart.item_count > 0 ? ' <span id="sl-cart-header-count">(' + cart.item_count + ')</span>' : '');
			$('#sl-slide-cart-title').html(titleHtml);
			$('#sl-floating-cart-badge').text(cart.item_count);
			$('#sl-floating-cart-total').html(cart.subtotal_html);

			if (cart.item_count > 0) {
				$('#sl-floating-cart').addClass('sl-floating-cart--has-items');
			} else {
				$('#sl-floating-cart').removeClass('sl-floating-cart--has-items');
			}

			// Freeship progress bar update (Kho Voucher Module + Drawer + In-page + Picker Modal)
			if (cart.freeship) {
				var fs = cart.freeship;
				var $fBars = $('#sl-module-freeship-bar, #sl-drawer-freeship-bar, #sl-inpage-freeship-bar, #sl-picker-freeship-bar');

				$('#sl-module-freeship-text, #sl-drawer-freeship-text, #sl-inpage-freeship-text, #sl-picker-freeship-text').html(fs.message || '');
				$('#sl-module-freeship-progress, #sl-drawer-freeship-progress, #sl-inpage-freeship-progress, #sl-picker-freeship-progress').css('width', (fs.percentage || 0) + '%');
				$('#sl-module-freeship-percent, #sl-drawer-freeship-percent, #sl-inpage-freeship-percent, #sl-picker-freeship-percent').text((fs.percentage || 0) + '%');

				if (fs.is_reached) {
					$fBars.addClass('sl-freeship-bar--reached');
				} else {
					$fBars.removeClass('sl-freeship-bar--reached');
				}
			}

			// Subtotal & Totals (Tạm tính & Tổng thanh toán)
			var originalSubtotalHtml = cart.original_subtotal_html || cart.subtotal_html;
			$('#sl-cart-total-val').html(cart.total_html);
			$('#sl-cart-subtotal-val').html(originalSubtotalHtml);
			$('#sl-inpage-subtotal').html(originalSubtotalHtml);
			$('#sl-inpage-total').html(cart.total_html);
			$('#sl-sticky-total').html(cart.total_html);

			// Slide Cart Drawer Totals Breakdown
			if (cart.total_saved_amount > 0) {
				$('#sl-drawer-subtotal-row').show();
				$('#sl-drawer-subtotal-val').html(originalSubtotalHtml);
				$('#sl-drawer-discount-row').show();
				$('#sl-drawer-discount-val').html('-' + cart.total_saved_html);
			} else {
				$('#sl-drawer-subtotal-row').hide();
				$('#sl-drawer-discount-row').hide();
			}

			// Item discount & Coupon discount rows in in-page summary
			if (cart.item_discount_total > 0) {
				if (!$('#sl-inpage-item-discount-row').length) {
					$('#sl-inpage-subtotal').closest('.sl-summary-row').after(
						'<div class="sl-summary-row sl-summary-discount" id="sl-inpage-item-discount-row">' +
						'<span>Giảm giá sản phẩm</span><strong class="sl-discount-val">-' + cart.item_discount_html + '</strong></div>'
					);
				} else {
					$('#sl-inpage-item-discount-row').show().find('.sl-discount-val').html('-' + cart.item_discount_html);
				}
			} else {
				$('#sl-inpage-item-discount-row').hide();
			}

			if (cart.coupon_discount_total > 0) {
				if (!$('#sl-inpage-coupon-discount-row').length) {
					var $targetRow = $('#sl-inpage-item-discount-row').length ? $('#sl-inpage-item-discount-row') : $('#sl-inpage-subtotal').closest('.sl-summary-row');
					$targetRow.after(
						'<div class="sl-summary-row sl-summary-discount" id="sl-inpage-coupon-discount-row">' +
						'<span>Voucher áp dụng</span><strong class="sl-discount-val">-' + cart.coupon_discount_html + '</strong></div>'
					);
				} else {
					$('#sl-inpage-coupon-discount-row').show().find('.sl-discount-val').html('-' + cart.coupon_discount_html);
				}
			} else {
				$('#sl-inpage-coupon-discount-row').hide();
			}

			// Savings Alert Box (In-page)
			if (cart.total_saved_amount > 0) {
				$('#sl-inpage-savings-box').show().find('strong').html(cart.total_saved_html);
			} else {
				$('#sl-inpage-savings-box').hide();
			}

			// In-page Cart line items update
			if ($('.sl-cart-item--inpage, .sl-cart-row').length || $('.sl-cart-items-card').length) {
				if (cart.is_empty) {
					// Replace cart content with empty state — no page reload needed.
					var shopUrl = cart.cart_url ? cart.cart_url.replace(/\/cart\/?$/, '/shop/') : '/shop/';
					var emptyBox = '<div class="sl-cart-empty-box">' +
						'<div class="sl-cart-empty-box__icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg></div>' +
						'<h2>' + (config.i18n.emptyTitle || 'Giỏ hàng của bạn đang trống') + '</h2>' +
						'<p>' + (config.i18n.emptySubtitle || 'Chưa có sản phẩm nào được chọn. Hãy tiếp tục khám phá các sản phẩm nổi bật nhé!') + '</p>' +
						'<a href="' + shopUrl + '" class="sl-btn sl-btn--primary sl-btn--lg">' + (config.i18n.shopNow || 'Khám phá sản phẩm ngay') + ' →</a>' +
						'</div>';
					$('.sl-cart-grid, .sl-freeship-bar--inpage').fadeOut(200, function () { $(this).remove(); });
					$('.sl-cart-header .sl-cart-count-pill').fadeOut(100);
					$('.sl-cart-container').append(emptyBox);
				} else {
					// Remove stale rows that no longer exist in the cart.
					var cartKeys = cart.items.map(function (it) { return it.key; });
					$('.sl-cart-item--inpage, .sl-cart-row, .sl-cart-item--tr').each(function () {
						var rowKey = $(this).data('key');
						if (cartKeys.indexOf(rowKey) === -1) {
							$(this).fadeOut(200, function () { $(this).remove(); });
						}
					});

					// Update existing rows with fresh data.
					$.each(cart.items, function (i, item) {
						var $row = $('.sl-cart-item--inpage[data-key="' + item.key + '"], .sl-cart-row[data-key="' + item.key + '"], .sl-cart-item--tr[data-key="' + item.key + '"]');
						if ($row.length) {
							$row.find('.sl-qty-input').val(item.quantity);
							$row.find('.sl-item-unit-price').html(item.price_html);
							$row.find('.sl-item-line-total').html(item.line_total);
							// Update regular/sale price displays.
							var regDisplay = item.regular_line_total_html || item.regular_price_html;
							if (regDisplay) {
								if ($row.find('.sl-cart-item__regular-price').length) {
									$row.find('.sl-cart-item__regular-price').html(regDisplay).show();
								}
							} else {
								$row.find('.sl-cart-item__regular-price').hide();
							}
						}
					});
				}
			}

			// Empty state or rebuild list
			if (cart.is_empty) {
				var emptyHtml = '<div class="sl-cart-empty-state">' +
					'<div class="sl-cart-empty-state__icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg></div>' +
					'<h3 class="sl-cart-empty-state__title">' + (config.i18n.emptyTitle || 'Giỏ hàng của bạn đang trống') + '</h3>' +
					'<p class="sl-cart-empty-state__text">' + (config.i18n.emptySubtitle || 'Điền giỏ hàng của bạn với những mặt hàng tuyệt vời') + '</p>' +
					'<button type="button" class="sl-btn sl-btn--primary sl-slide-cart-close-btn">' + (config.i18n.shopNow || 'Mua ngay') + '</button>' +
					'</div>';
				$('#sl-slide-cart-body').html(emptyHtml);
				$('#sl-slide-cart-footer').hide();
			} else {
				$('#sl-slide-cart-footer').show();
				var itemsHtml = '';
				$.each(cart.items, function (i, item) {
					var varHtml = '';
					if (item.is_variable && item.attributes_config && item.attributes_config.length) {
						varHtml = '<div class="sl-cart-item__variations">';
						$.each(item.attributes_config, function (ai, attr) {
							varHtml += '<div class="sl-cart-attr-group">' +
								'<select class="sl-cart-attr-select" data-attribute="' + attr.key + '" data-key="' + item.key + '" aria-label="' + attr.label + '">';
							$.each(attr.options, function (oi, opt) {
								var selected = (opt.slug === attr.current_value) ? ' selected' : '';
								varHtml += '<option value="' + opt.slug + '"' + selected + '>' + opt.name + '</option>';
							});
							varHtml += '</select></div>';
						});
						varHtml += '</div>';
					} else if (item.variation_text) {
						varHtml = '<div class="sl-cart-item__meta">' + item.variation_text + '</div>';
					}

					var regPriceHtml = '';
					var regularDisplay = item.regular_line_total_html || item.regular_price_html;
					if (regularDisplay) {
						regPriceHtml = '<del class="sl-cart-item__regular-price">' + regularDisplay + '</del>';
					}

					var savingsHtml = '';
					if (item.discount_badge) {
						savingsHtml = '<div class="sl-cart-item__savings">' + item.discount_badge + '</div>';
					} else if (item.saved_amount_html) {
						savingsHtml = '<div class="sl-cart-item__savings">Tiết kiệm ' + item.saved_amount_html + '</div>';
					}

					itemsHtml += '<article class="sl-cart-item" data-key="' + item.key + '">' +
						'<div class="sl-cart-item__thumb">' + item.thumbnail + '</div>' +
						'<div class="sl-cart-item__details">' +
						'<div class="sl-cart-item__header-row">' +
						'<h4 class="sl-cart-item__title"><a href="' + (item.permalink || '#') + '">' + item.name + '</a></h4>' +
						'<button type="button" class="sl-cart-item__remove-btn sl-cart-item__remove" aria-label="Xóa"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button>' +
						'</div>' +
						'<div class="sl-cart-item__bottom-row">' +
						'<div class="sl-cart-item__left-col">' +
						varHtml +
						'<div class="sl-qty-stepper">' +
						'<button type="button" class="sl-qty-btn sl-qty-minus">—</button>' +
						'<input type="number" class="sl-input sl-qty-input" value="' + item.quantity + '" readonly />' +
						'<button type="button" class="sl-qty-btn sl-qty-plus">+</button>' +
						'</div>' +
						'</div>' +
						'<div class="sl-cart-item__right-col">' +
						'<div class="sl-cart-item__prices-wrap">' +
						regPriceHtml +
						'<strong class="sl-cart-item__price">' + item.line_total + '</strong>' +
						'</div>' +
						savingsHtml +
						'</div>' +
						'</div>' +
						'</div>' +
						'</article>';
				});
				$('#sl-cart-items-list').html(itemsHtml);
			}

			// Render Applied Coupons Tags
			var appliedHtml = '';
			if (cart.coupons && cart.coupons.length) {
				$.each(cart.coupons, function (i, cp) {
					appliedHtml += '<span class="sl-coupon-tag">' +
						cp.code +
						'<button type="button" class="sl-coupon-tag__remove" data-code="' + cp.code + '">&times;</button>' +
						'</span>';
				});
			}
			$('.sl-applied-coupons').html(appliedHtml);

			// Render Available Vouchers in Drawer & In-page Modal
			if (cart.available_coupons) {
				var vHtml = this.renderVouchersHtml(cart.available_coupons);
				$('#sl-voucher-list').html(vHtml);
				$('#sl-inpage-voucher-list, #sl-inpage-voucher-modal-list').html(vHtml);
			}

			// Trigger standard WC fragments update if inpage
			$(document.body).trigger('wc_fragment_refresh');
		},

		renderVouchersHtml: function (coupons) {
			if (!coupons || !coupons.length) {
				return '<div class="sl-voucher-empty">' +
					'<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"></path></svg>' +
					'<p>Hiện chưa có mã giảm giá công khai nào trong kho.</p>' +
					'</div>';
			}

			var html = '<div class="sl-voucher-section-title">Mã ưu đãi có sẵn</div><div class="sl-voucher-cards-list">';
			$.each(coupons, function (i, v) {
				var appliedClass = v.is_applied ? ' is-applied' : '';
				var disabledClass = (!v.is_usable) ? ' is-disabled' : '';
				var isFreeship = v.free_shipping || (v.discount_type === 'free_shipping') || (v.badge && v.badge.indexOf('FREESHIP') !== -1);
				var freeshipClass = isFreeship ? ' sl-coupon-ticket--freeship' : '';
				var typeLabel = isFreeship ? 'FREESHIP' : 'GIẢM GIÁ';

				var tipHtml = (!v.is_usable && v.amount_needed_html) ?
					'<div class="sl-coupon-ticket__progress-tip"><span class="sl-progress-tip-text">Mua thêm ' + v.amount_needed_html + ' để dùng</span></div>' : '';

				var discountVal = v.discount_label || v.badge;
				var expiryVal = v.expiry_text ? ('HSD: ' + v.expiry_text) : 'Không thời hạn';

				var tooltipHtml = '';
				if (v.description) {
					tooltipHtml = '<span class="sl-coupon-ticket__info-btn sl-voucher-card__tooltip-trigger" tabindex="0" aria-label="Xem chi tiết & điều kiện">' +
						'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>' +
						'<span class="sl-voucher-card__tooltip-content">' +
						'<span class="sl-tooltip-title">Điều kiện áp dụng:</span>' +
						v.description +
						'</span>' +
						'</span>';
				}

				var actionHtml = '';
				if (v.is_applied) {
					actionHtml = '<span class="sl-coupon-ticket__applied-badge">✓ Đã dùng</span>';
				} else if (v.is_usable) {
					actionHtml = '<button type="button" class="sl-coupon-ticket__btn sl-voucher-apply-btn" data-code="' + v.code + '">Dùng mã</button>';
				} else {
					actionHtml = '<button type="button" class="sl-coupon-ticket__btn is-disabled" disabled>Chưa đủ</button>';
				}

				html += '<div class="sl-coupon-ticket' + appliedClass + disabledClass + freeshipClass + '" data-code="' + v.code + '">' +
					'<div class="sl-coupon-ticket__left">' +
					'<span class="sl-coupon-ticket__type">' + typeLabel + '</span>' +
					'<strong class="sl-coupon-ticket__val">' + v.badge + '</strong>' +
					'</div>' +
					'<div class="sl-coupon-ticket__divider"></div>' +
					'<div class="sl-coupon-ticket__right">' +
					'<div class="sl-coupon-ticket__header">' +
					'<span class="sl-coupon-ticket__code">' + v.code + '</span>' +
					tooltipHtml +
					'</div>' +
					'<div class="sl-coupon-ticket__meta">' +
					'<span class="sl-coupon-ticket__discount-text">' + discountVal + '</span>' +
					'</div>' +
					tipHtml +
					'<div class="sl-coupon-ticket__footer">' +
					'<span class="sl-coupon-ticket__expiry">' + expiryVal + '</span>' +
					'<div class="sl-coupon-ticket__action">' + actionHtml + '</div>' +
					'</div>' +
					'</div>' +
					'</div>';
			});
			html += '</div>';
			return html;
		},

		initStickyCheckoutObserver: function () {
			var $stickyBar = $('#sl-sticky-checkout-bar');
			var $targetCta = $('.sl-checkout-cta-wrap');

			if (!$stickyBar.length || !$targetCta.length) return;

			if ('IntersectionObserver' in window) {
				var observer = new IntersectionObserver(function (entries) {
					entries.forEach(function (entry) {
						if (entry.isIntersecting) {
							// Nút bấm chính ở trong viewport -> Ẩn thanh Sticky Bar mượt mà
							$stickyBar.removeClass('sl-sticky-checkout-bar--visible');
						} else {
							// Nút bấm chính ra khỏi viewport -> Hiện thanh Sticky Bar mượt mà
							$stickyBar.addClass('sl-sticky-checkout-bar--visible');
						}
					});
				}, {
					threshold: 0.05
				});

				observer.observe($targetCta[0]);
			} else {
				// Fallback cho trình duyệt cũ
				$(window).on('scroll resize', function () {
					var targetTop = $targetCta.offset().top;
					var targetBottom = targetTop + $targetCta.outerHeight();
					var scrollTop = $(window).scrollTop();
					var viewportHeight = $(window).height();

					if (scrollTop + viewportHeight < targetTop || scrollTop > targetBottom) {
						$stickyBar.addClass('sl-sticky-checkout-bar--visible');
					} else {
						$stickyBar.removeClass('sl-sticky-checkout-bar--visible');
					}
				}).trigger('scroll');
			}
		}
	};

	$(document).ready(function () {
		SlideCart.init();
	});

})(jQuery);
