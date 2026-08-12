/**
 * OmniWP Slide Cart & Cart In-page Controller
 */
(function ($) {
	'use strict';

	var config = window.omniwpCartConfig || {};

	var SlideCart = {
		init: function () {
			this.cacheDOM();
			this.bindEvents();
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
			this.$closeBtn.on('click', function (e) {
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
					if (config.autoOpenOnAdd) {
						self.open();
					}
				});
			});

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
					self.updateQuantity(key, current + 1);
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
					self.updateQuantity(key, current - 1);
				} else if (current === 1) {
					self.removeItem(key);
				}
			});

			// Remove item
			$(document).on('click', '.sl-cart-item__remove', function (e) {
				e.preventDefault();
				var $row = $(this).closest('[data-key]');
				var key  = $row.data('key');
				self.removeItem(key);
			});

			// Apply Coupon
			$(document).on('submit', '#sl-cart-coupon-form, #sl-inpage-coupon-form', function (e) {
				e.preventDefault();
				var $form = $(this);
				var code  = $form.find('input[type="text"]').val().trim();
				if (!code) return;

				var $btn = $form.find('button[type="submit"]');
				$btn.prop('disabled', true).text('...');

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
						$btn.prop('disabled', false).text(config.i18n.applyCoupon || 'Áp dụng');
						if (res.success) {
							$('#sl-coupon-message').removeClass('is-error').addClass('is-success').text(res.data.message).show();
							self.renderCart(res.data.cart);
						} else {
							$('#sl-coupon-message').removeClass('is-success').addClass('is-error').text(res.data.message).show();
						}
					},
					error: function () {
						$btn.prop('disabled', false).text(config.i18n.applyCoupon || 'Áp dụng');
					}
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
		},

		open: function () {
			this.$overlay.addClass('is-active').attr('aria-hidden', 'false');
			this.$drawer.addClass('is-open').attr('aria-hidden', 'false');
			$('body').css('overflow', 'hidden');
		},

		close: function () {
			this.$overlay.removeClass('is-active').attr('aria-hidden', 'true');
			this.$drawer.removeClass('is-open').attr('aria-hidden', 'true');
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
			$.ajax({
				url: config.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'omniwp_update_cart_qty',
					nonce: config.nonce,
					key: key,
					quantity: qty
				},
				success: function (res) {
					self.$drawer.removeClass('is-loading');
					if (res.success) {
						self.renderCart(res.data);
					}
				},
				error: function () {
					self.$drawer.removeClass('is-loading');
				}
			});
		},

		removeItem: function (key) {
			var self = this;
			self.$drawer.addClass('is-loading');
			$.ajax({
				url: config.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'omniwp_remove_cart_item',
					nonce: config.nonce,
					key: key
				},
				success: function (res) {
					self.$drawer.removeClass('is-loading');
					if (res.success) {
						self.renderCart(res.data);
					}
				},
				error: function () {
					self.$drawer.removeClass('is-loading');
				}
			});
		},

		renderCart: function (cart) {
			if (!cart) return;

			// Header & floating count
			$('#sl-cart-header-count').text('(' + cart.item_count + ')');
			$('#sl-floating-cart-badge').text(cart.item_count);
			$('#sl-floating-cart-total').html(cart.subtotal_html);

			if (cart.item_count > 0) {
				$('#sl-floating-cart').addClass('sl-floating-cart--has-items');
			} else {
				$('#sl-floating-cart').removeClass('sl-floating-cart--has-items');
			}

			// Freeship bar
			if (cart.freeship && cart.freeship.enabled) {
				$('#sl-freeship-text').text(cart.freeship.message);
				$('#sl-freeship-progress').css('width', cart.freeship.percentage + '%');
				if (cart.freeship.is_reached) {
					$('#sl-freeship-bar').addClass('sl-freeship-bar--reached');
				} else {
					$('#sl-freeship-bar').removeClass('sl-freeship-bar--reached');
				}
			}

			// Subtotal & Totals
			$('#sl-cart-subtotal-val').html(cart.subtotal_html);
			$('#sl-cart-total-val').html(cart.total_html);
			$('#sl-inpage-subtotal').html(cart.subtotal_html);
			$('#sl-inpage-total').html(cart.total_html);

			if (cart.discount_total > 0) {
				$('#sl-cart-discount-val').html('-' + cart.discount_total);
			}

			// Empty state or rebuild list
			if (cart.is_empty) {
				var emptyHtml = '<div class="sl-cart-empty-state">' +
					'<div class="sl-cart-empty-state__icon">🛍️</div>' +
					'<h3 class="sl-cart-empty-state__title">' + (config.i18n.emptyTitle || 'Giỏ hàng đang trống') + '</h3>' +
					'<p class="sl-cart-empty-state__text">' + (config.i18n.continueShop || 'Hãy tiếp tục mua sắm nhé') + '</p>' +
					'</div>';
				$('#sl-slide-cart-body').html(emptyHtml);
				$('#sl-slide-cart-footer').hide();
			} else {
				$('#sl-slide-cart-footer').show();
				var itemsHtml = '';
				$.each(cart.items, function (i, item) {
					itemsHtml += '<article class="sl-cart-item" data-key="' + item.key + '">' +
						'<div class="sl-cart-item__thumb">' + item.thumbnail + '</div>' +
						'<div class="sl-cart-item__details">' +
						'<h4 class="sl-cart-item__title"><a href="' + (item.permalink || '#') + '">' + item.name + '</a></h4>' +
						(item.variation_text ? '<div class="sl-cart-item__meta">' + item.variation_text + '</div>' : '') +
						'<div class="sl-cart-item__price">' + item.price_html + '</div>' +
						'<div class="sl-cart-item__actions">' +
						'<div class="sl-qty-stepper">' +
						'<button type="button" class="sl-qty-btn sl-qty-minus">-</button>' +
						'<input type="number" class="sl-qty-input" value="' + item.quantity + '" readonly />' +
						'<button type="button" class="sl-qty-btn sl-qty-plus">+</button>' +
						'</div>' +
						'<button type="button" class="sl-cart-item__remove"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button>' +
						'</div>' +
						'</div>' +
						'</article>';
				});
				$('#sl-cart-items-list').html(itemsHtml);
			}

			// Trigger standard WC fragments update if inpage
			$(document.body).trigger('wc_fragment_refresh');
		}
	};

	$(document).ready(function () {
		SlideCart.init();
	});

})(jQuery);
