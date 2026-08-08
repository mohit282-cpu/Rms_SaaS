/* ==========================================================================
   QR RESTAURANT - MODERN SPATIAL & TAILWIND INTERACTIVE JAVASCRIPT
   ========================================================================== */

// Global Cart State
let cart = JSON.parse(localStorage.getItem('cart')) || [];

// Save Cart to LocalStorage and update all UI elements
function saveCart() {
    localStorage.setItem('cart', JSON.stringify(cart));
    updateCartCount();
    updateCartPanel();
    if (typeof renderCartPage === 'function') {
        renderCartPage();
    }
}

// Format Price (Nepali Rupees)
function formatPrice(price) {
    const num = parseFloat(price) || 0;
    if (num === Math.floor(num)) {
        return 'Rs. ' + num.toLocaleString('en-IN');
    }
    return 'Rs. ' + num.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Update Cart Count Badges
function updateCartCount() {
    const totalCount = cart.reduce((sum, item) => sum + item.quantity, 0);
    const badges = document.querySelectorAll('.cart-badge');
    badges.forEach(badge => {
        badge.textContent = totalCount;
        badge.style.display = totalCount > 0 ? 'flex' : 'none';
    });
}

// Calculate Cart Total Amount
function getCartTotal() {
    return cart.reduce((sum, item) => sum + (parseFloat(item.price) * item.quantity), 0);
}

// Add Item to Cart
function addToCart(itemId, itemName, itemPrice, customizations = {}) {
    const existingIndex = cart.findIndex(item => item.id === itemId && JSON.stringify(item.customizations || {}) === JSON.stringify(customizations));

    if (existingIndex > -1) {
        cart[existingIndex].quantity++;
    } else {
        cart.push({
            id: itemId,
            name: itemName,
            price: parseFloat(itemPrice),
            quantity: 1,
            customizations: customizations
        });
    }

    saveCart();
    showToast(`Added ${itemName} to cart!`, 'success');
    if (window.innerWidth < 1024) {
        openCartPanel();
    }
}

// Update Item Quantity by Cart Array Index
function updateQuantityByIndex(index, change) {
    if (cart[index]) {
        cart[index].quantity += change;
        if (cart[index].quantity <= 0) {
            cart.splice(index, 1);
        }
        saveCart();
    }
}

// Remove Item from Cart by Array Index
function removeFromCartByIndex(index) {
    if (cart[index]) {
        cart.splice(index, 1);
        saveCart();
        showToast('Item removed from cart', 'info');
    }
}

// Legacy wrappers for backward compatibility
function updateQuantity(itemId, change, customizations = {}) {
    const index = cart.findIndex(item => item.id === itemId && JSON.stringify(item.customizations || {}) === JSON.stringify(customizations));
    if (index > -1) updateQuantityByIndex(index, change);
}

function removeFromCart(itemId, customizations = {}) {
    const index = cart.findIndex(item => item.id === itemId && JSON.stringify(item.customizations || {}) === JSON.stringify(customizations));
    if (index > -1) removeFromCartByIndex(index);
}

// ==================== IMAGE ZOOM LIGHTBOX MODAL ====================
function openImageZoomModal(imgSrc, title, price) {
    const modal = document.getElementById('imageZoomModal');
    const imgEl = document.getElementById('zoomModalImage');
    const titleEl = document.getElementById('zoomModalTitle');
    const priceEl = document.getElementById('zoomModalPrice');

    if (imgEl) imgEl.src = imgSrc;
    if (titleEl) titleEl.textContent = title || '';
    if (priceEl) priceEl.textContent = price ? 'Rs. ' + price : '';

    if (modal) {
        modal.classList.remove('opacity-0', 'pointer-events-none');
        modal.children[1].classList.remove('scale-90');
        modal.children[1].classList.add('scale-100');
    }
    if (navigator.vibrate) navigator.vibrate(40);
}

function closeImageZoomModal() {
    const modal = document.getElementById('imageZoomModal');
    if (modal) {
        modal.classList.add('opacity-0', 'pointer-events-none');
        modal.children[1].classList.remove('scale-100');
        modal.children[1].classList.add('scale-90');
    }
}

// ==================== SLIDE-OUT CART PANEL ====================
function openCartPanel() {
    const overlay = document.querySelector('.cart-overlay');
    const panel = document.querySelector('.cart-panel');
    if (overlay) {
        overlay.classList.add('active');
        overlay.classList.remove('opacity-0', 'pointer-events-none');
    }
    if (panel) {
        panel.classList.add('active');
        panel.classList.remove('translate-x-full');
    }
    updateCartPanel();
}

function closeCartPanel() {
    const overlay = document.querySelector('.cart-overlay');
    const panel = document.querySelector('.cart-panel');
    if (overlay) {
        overlay.classList.remove('active');
        overlay.classList.add('opacity-0', 'pointer-events-none');
    }
    if (panel) {
        panel.classList.remove('active');
        panel.classList.add('translate-x-full');
    }
}

function updateCartPanel() {
    const mobileContainer = document.getElementById('mobileCartItems');
    const desktopContainer = document.getElementById('desktopCartItems');
    const checkoutContainer = document.getElementById('checkoutItemsList');
    const classContainers = document.querySelectorAll('.cart-items');

    const totalEl = document.getElementById('cartTotal');
    const desktopTotalEl = document.getElementById('desktopCartTotal');
    const checkoutSubtotalEl = document.getElementById('checkoutSubtotal');
    const checkoutBtn = document.getElementById('checkoutBtn');
    const desktopCheckoutBtn = document.getElementById('desktopCheckoutBtn');

    const isCartEmpty = (cart.length === 0);
    const totalFormatted = formatPrice(getCartTotal());

    if (totalEl) totalEl.textContent = totalFormatted;
    if (desktopTotalEl) desktopTotalEl.textContent = totalFormatted;
    if (checkoutSubtotalEl) checkoutSubtotalEl.textContent = totalFormatted;

    if (checkoutBtn) {
        if (isCartEmpty) checkoutBtn.classList.add('opacity-50', 'pointer-events-none');
        else checkoutBtn.classList.remove('opacity-50', 'pointer-events-none');
    }
    if (desktopCheckoutBtn) {
        if (isCartEmpty) desktopCheckoutBtn.classList.add('opacity-50', 'pointer-events-none');
        else desktopCheckoutBtn.classList.remove('opacity-50', 'pointer-events-none');
    }

    const htmlContent = isCartEmpty ? `
        <div class="text-center py-10 text-zinc-500">
            <div class="text-4xl mb-2">🛒</div>
            <p class="font-bold text-sm">Your cart is empty</p>
        </div>
    ` : cart.map((item, idx) => {
        let customText = '';
        if (item.customizations) {
            if (item.customizations.spice_level) customText += `🌶️ ${item.customizations.spice_level}`;
            if (item.customizations.extras && item.customizations.extras.length > 0) {
                const extraNames = item.customizations.extras.map(e => e.name).join(', ');
                customText += ` | + ${extraNames}`;
            }
        }
        return `
            <div class="bg-zinc-950/80 border border-zinc-800/60 rounded-2xl p-3 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2.5 min-w-0 flex-1">
                    <div class="w-9 h-9 rounded-xl bg-zinc-900 border border-zinc-800 flex items-center justify-center text-base shrink-0">🍽️</div>
                    <div class="min-w-0 flex-1">
                        <div class="font-black text-xs text-white truncate">${item.name}</div>
                        ${customText ? `<div class="text-[10px] text-zinc-400 truncate">${customText}</div>` : ''}
                        <div class="text-xs font-black text-amber-400 mt-0.5">${formatPrice(item.price * item.quantity)}</div>
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <div class="flex items-center gap-1.5 bg-zinc-900 border border-zinc-800 rounded-xl p-1">
                        <button class="w-6 h-6 rounded-lg bg-zinc-800 text-white font-black text-xs flex items-center justify-center active:bg-amber-500 active:text-zinc-950" onclick="updateQuantityByIndex(${idx}, -1)">−</button>
                        <span class="text-xs font-black text-white w-4 text-center">${item.quantity}</span>
                        <button class="w-6 h-6 rounded-lg bg-zinc-800 text-white font-black text-xs flex items-center justify-center active:bg-amber-500 active:text-zinc-950" onclick="updateQuantityByIndex(${idx}, 1)">+</button>
                    </div>
                    <button class="text-xs text-rose-400 font-bold px-1" onclick="removeFromCartByIndex(${idx})">✕</button>
                </div>
            </div>
        `;
    }).join('');

    classContainers.forEach(c => c.innerHTML = htmlContent);
    if (mobileContainer) mobileContainer.innerHTML = htmlContent;
    if (desktopContainer) desktopContainer.innerHTML = htmlContent;
    if (checkoutContainer) checkoutContainer.innerHTML = htmlContent;
}

// ==================== ITEM CUSTOMIZATION MODAL ====================
let currentCustomItem = null;

function openCustomModal(itemId, itemName, itemPrice, description, prepTime, rawName, rawDesc) {
    currentCustomItem = {
        id: itemId,
        name: rawName || itemName,
        price: parseFloat(itemPrice),
        quantity: 1
    };

    const titleEl = document.getElementById('customModalTitle');
    const descEl = document.getElementById('customModalDesc');
    const priceEl = document.getElementById('customModalPrice');
    const qtyEl = document.getElementById('customModalQty');
    const notesEl = document.getElementById('customModalNotes');
    const addonsContainer = document.getElementById('customAddonsContainer');
    const addonsList = document.getElementById('customModalAddonsList');

    if (titleEl) titleEl.textContent = rawName || itemName;
    if (descEl) descEl.textContent = rawDesc || description || '';
    if (priceEl) priceEl.textContent = formatPrice(itemPrice);
    if (qtyEl) qtyEl.textContent = '1';
    if (notesEl) notesEl.value = '';

    // Parse dish-specific add-ons from data-addons attribute
    let addonsStr = '';
    const card = document.getElementById('item-card-' + itemId);
    if (card && card.dataset.addons) {
        addonsStr = card.dataset.addons.trim();
    }

    if (addonsList) addonsList.innerHTML = '';
    
    if (addonsStr) {
        const lines = addonsStr.split(/\r?\n/);
        let count = 0;
        lines.forEach(line => {
            line = line.trim();
            if (!line) return;
            const parts = line.split(':');
            const aName = parts[0].trim();
            const aPrice = parseFloat(parts[1]) || 0;
            
            const label = document.createElement('label');
            label.className = 'flex items-center justify-between bg-zinc-900 border border-zinc-800 rounded-xl p-3 cursor-pointer text-xs font-bold text-zinc-300 hover:border-zinc-700';
            label.innerHTML = `
                <span>${aName} (+Rs. ${aPrice})</span>
                <input type="checkbox" class="custom-addon-checkbox w-4 h-4 accent-amber-500" data-name="${aName}" data-price="${aPrice}" onchange="updateCustomModalTotal()">
            `;
            addonsList.appendChild(label);
            count++;
        });

        if (count > 0 && addonsContainer) {
            addonsContainer.classList.remove('hidden');
        } else if (addonsContainer) {
            addonsContainer.classList.add('hidden');
        }
    } else if (addonsContainer) {
        addonsContainer.classList.add('hidden');
    }

    updateCustomModalTotal();

    const modal = document.getElementById('customModal');
    if (modal) {
        modal.classList.remove('opacity-0', 'pointer-events-none');
        modal.children[1].classList.remove('translate-y-full', 'lg:translate-y-0');
    }
}

function closeCustomModal() {
    const modal = document.getElementById('customModal');
    if (modal) {
        modal.classList.add('opacity-0', 'pointer-events-none');
        modal.children[1].classList.add('translate-y-full');
    }
    currentCustomItem = null;
}

function customModalChangeQty(delta) {
    if (!currentCustomItem) return;
    currentCustomItem.quantity += delta;
    if (currentCustomItem.quantity < 1) currentCustomItem.quantity = 1;

    const qtyEl = document.getElementById('customModalQty');
    if (qtyEl) qtyEl.textContent = currentCustomItem.quantity;

    updateCustomModalTotal();
}

function updateCustomModalTotal() {
    if (!currentCustomItem) return;

    let basePrice = currentCustomItem.price;
    let extraTotal = 0;

    document.querySelectorAll('.custom-addon-checkbox').forEach(cb => {
        if (cb.checked) {
            extraTotal += parseFloat(cb.dataset.price || 0);
        }
    });

    const itemTotal = (basePrice + extraTotal) * currentCustomItem.quantity;
    const totalEl = document.getElementById('customModalTotal');
    if (totalEl) totalEl.textContent = formatPrice(itemTotal);
}

function addCustomToCart() {
    if (!currentCustomItem) return;

    let extraNames = [];
    let extraTotal = 0;

    document.querySelectorAll('.custom-addon-checkbox').forEach(cb => {
        if (cb.checked) {
            extraNames.push(cb.dataset.name);
            extraTotal += parseFloat(cb.dataset.price || 0);
        }
    });

    const notesEl = document.getElementById('customModalNotes');
    const notes = notesEl ? notesEl.value.trim() : '';

    let optionsArr = [];
    if (extraNames.length > 0) {
        optionsArr.push('Addons: ' + extraNames.join(', '));
    }
    if (notes) {
        optionsArr.push('Note: ' + notes);
    }
    const optionsStr = optionsArr.join(' | ');
    const finalUnitPrice = currentCustomItem.price + extraTotal;

    const customizations = {
        optionsStr: optionsStr,
        extras: extraNames,
        notes: notes
    };

    for (let i = 0; i < currentCustomItem.quantity; i++) {
        addToCart(currentCustomItem.id, currentCustomItem.name, finalUnitPrice, customizations);
    }

    closeCustomModal();
    showToast(`Added ${currentCustomItem.quantity}x ${currentCustomItem.name} to cart!`, 'success');
}

// Call Waiter API
function callWaiter() {
    const tableNum = new URLSearchParams(window.location.search).get('table') || '1';
    fetch('api/call-waiter.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'table=' + encodeURIComponent(tableNum)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast(data.message || `🔔 Waiter call sent for Table ${tableNum}! Staff on the way.`, 'success');
            if (navigator.vibrate) navigator.vibrate(80);
        } else {
            showToast(data.message || 'Failed to call waiter', 'warning');
        }
    })
    .catch(err => console.error(err));
}

// Animated Tick Modal Popup
function showOrderPlacedTickModal(order, redirectUrl) {
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4';
    modal.innerHTML = `
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 text-center max-w-xs w-full shadow-2xl scale-95 transition-transform duration-300">
            <div class="w-16 h-16 rounded-full bg-emerald-500/20 border-2 border-emerald-500 flex items-center justify-center text-emerald-400 font-black text-3xl mx-auto mb-3">✓</div>
            <h3 class="font-black text-white text-lg mb-1">Order Confirmed!</h3>
            <p class="text-xs text-zinc-400 mb-4">Order #${order.id} for Table ${order.table_number} has been sent to the kitchen.</p>
            <div class="w-full bg-zinc-950 rounded-full h-1.5 overflow-hidden">
                <div class="bg-amber-500 h-full w-full animate-pulse"></div>
            </div>
        </div>
    `;
    document.body.appendChild(modal);

    playSuccessChime();
    if (navigator.vibrate) navigator.vibrate([100, 50, 100]);

    localStorage.removeItem('cart');
    cart = [];

    setTimeout(() => {
        window.location.href = redirectUrl;
    }, 2000);
}

// Toast Notifications
function showToast(message, type = 'info') {
    const existing = document.querySelector('.spatial-toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.className = 'spatial-toast fixed top-4 left-1/2 -translate-x-1/2 z-50 bg-zinc-900 border border-zinc-800 text-zinc-100 px-4 py-2.5 rounded-full shadow-2xl flex items-center gap-2 text-xs font-bold whitespace-nowrap';

    let icon = 'ℹ️';
    if (type === 'success') icon = '✅';
    if (type === 'warning') icon = '⚠️';
    if (type === 'error') icon = '❌';

    toast.innerHTML = `<span>${icon}</span> <span>${message}</span>`;
    document.body.appendChild(toast);

    setTimeout(() => toast.remove(), 3500);
}

// Call Waiter API Helper
function callWaiterToTable(tableNum) {
    fetch('api/call-waiter.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'table=' + encodeURIComponent(tableNum)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showSpatialToast(`🔔 Waiter call sent for Table ${tableNum}! Staff on the way.`, 'success');
            if (typeof playSuccessChime === 'function') playSuccessChime();
        } else {
            showSpatialToast(data.message || 'Error sending waiter call.', 'warning');
        }
    })
    .catch(err => {
        showSpatialToast('Error calling waiter.', 'error');
    });
}

// Audio Chime
function playSuccessChime() {
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(587.33, audioCtx.currentTime);
        osc.frequency.exponentialRampToValueAtTime(880, audioCtx.currentTime + 0.15);
        gain.gain.setValueAtTime(0.3, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.3);
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.3);
    } catch (e) {}
}

// Utility for formatting time
function parseMySQLDate(str) {
    if (!str) return new Date();
    const t = str.split(/[- :]/);
    return new Date(Date.UTC(t[0], t[1]-1, t[2], t[3]||0, t[4]||0, t[5]||0));
}

function getTimeAgo(dateStr) {
    const date = parseMySQLDate(dateStr);
    const seconds = Math.floor((new Date() - date) / 1000);
    if (seconds < 60) return 'Just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    return `${hours}h ago`;
}

// Global Event Listeners
document.addEventListener('DOMContentLoaded', () => {
    updateCartCount();
    updateCartPanel();

    const overlay = document.querySelector('.cart-overlay');
    const closeBtn = document.querySelector('.cart-close');

    if (overlay) overlay.addEventListener('click', closeCartPanel);
    if (closeBtn) closeBtn.addEventListener('click', closeCartPanel);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeImageZoomModal();
            closeCustomModal();
            closeCartPanel();
        }
    });
});
