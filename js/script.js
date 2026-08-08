// QR Code Restaurant Ordering System - JavaScript

// Cart Management
let cart = JSON.parse(localStorage.getItem('cart')) || [];

// Save cart to localStorage
function saveCart() {
    localStorage.setItem('cart', JSON.stringify(cart));
    updateCartCount();
}

// Update cart count in header
function updateCartCount() {
    const cartCount = cart.reduce((sum, item) => sum + item.quantity, 0);
    const badges = document.querySelectorAll('.cart-badge');
    badges.forEach(badge => {
        badge.textContent = cartCount;
        badge.style.display = cartCount > 0 ? 'block' : 'none';
    });
}

// Add item to cart
function addToCart(itemId, itemName, itemPrice) {
    const existingItem = cart.find(item => item.id === itemId);
    
    if (existingItem) {
        existingItem.quantity++;
    } else {
        cart.push({
            id: itemId,
            name: itemName,
            price: itemPrice,
            quantity: 1
        });
    }
    
    saveCart();
    alert(itemName + ' added to cart!');
}

// Update item quantity in cart
function updateQuantity(itemId, change) {
    const item = cart.find(item => item.id === itemId);
    
    if (item) {
        item.quantity += change;
        
        if (item.quantity <= 0) {
            removeFromCart(itemId);
        } else {
            saveCart();
            if (typeof renderCart === 'function') {
                renderCart();
            }
        }
    }
}

// Remove item from cart
function removeFromCart(itemId) {
    cart = cart.filter(item => item.id !== itemId);
    saveCart();
    if (typeof renderCart === 'function') {
        renderCart();
    }
}

// Calculate cart total
function getCartTotal() {
    return cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
}

// Format price (Nepalese Rupee)
function formatPrice(price) {
    return 'Rs. ' + price.toFixed(2);
}

// Get table number from URL
function getTableFromURL() {
    const params = new URLSearchParams(window.location.search);
    return params.get('table');
}

// Menu Page Functions
function renderMenuItems(items) {
    const menuGrid = document.getElementById('menuGrid');
    if (!menuGrid) return;
    
    menuGrid.innerHTML = items.map(item => {
        const inCart = cart.find(c => c.id === item.id);
        const quantity = inCart ? inCart.quantity : 0;
        
        return `
            <div class="menu-item">
                <div class="menu-item-image">
                    ${item.image ? `<img src="images/${item.image}" alt="${item.name}" onerror="this.parentElement.innerHTML='🍽️'">` : '🍽️'}
                </div>
                <div class="menu-item-content">
                    <h3 class="menu-item-name">${item.name}</h3>
                    <p class="menu-item-description">${item.description}</p>
                    <div class="menu-item-footer">
                        <span class="menu-item-price">${formatPrice(item.price)}</span>
                        ${quantity > 0 ? `
                            <div class="quantity-control">
                                <button class="quantity-btn" onclick="updateQuantity(${item.id}, -1)">-</button>
                                <span class="quantity-value">${quantity}</span>
                                <button class="quantity-btn" onclick="updateQuantity(${item.id}, 1)">+</button>
                            </div>
                        ` : `
                            <button class="add-to-cart-btn" onclick="addToCart(${item.id}, '${item.name.replace(/'/g, "\\'")}', ${item.price})">Add to Cart</button>
                        `}
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function filterByCategory(categoryId) {
    const buttons = document.querySelectorAll('.category-btn');
    buttons.forEach(btn => btn.classList.remove('active'));
    
    const activeBtn = document.querySelector(`[data-category="${categoryId}"]`);
    if (activeBtn) activeBtn.classList.add('active');
    
    // Fetch filtered items via AJAX
    fetch(`api/menu.php?category_id=${categoryId}`)
        .then(response => response.json())
        .then(data => {
            renderMenuItems(data);
        })
        .catch(error => console.error('Error:', error));
}

// Cart Page Functions
function renderCart() {
    const cartItemsContainer = document.getElementById('cartItems');
    const cartTotalElement = document.getElementById('cartTotal');
    const checkoutBtn = document.getElementById('checkoutBtn');
    const emptyCart = document.getElementById('emptyCart');
    const cartSummary = document.getElementById('cartSummary');
    
    if (!cartItemsContainer) return;
    
    if (cart.length === 0) {
        cartItemsContainer.innerHTML = '';
        if (cartTotalElement) cartTotalElement.textContent = 'Rs. 0.00';
        if (checkoutBtn) checkoutBtn.disabled = true;
        if (emptyCart) emptyCart.style.display = 'block';
        if (cartSummary) cartSummary.style.display = 'none';
        return;
    }
    
    if (emptyCart) emptyCart.style.display = 'none';
    if (cartSummary) cartSummary.style.display = 'block';
    if (checkoutBtn) checkoutBtn.disabled = false;
    
    cartItemsContainer.innerHTML = cart.map(item => `
        <div class="cart-item">
            <div class="cart-item-image">🍽️</div>
            <div class="cart-item-details">
                <div class="cart-item-name">${item.name}</div>
                <div class="cart-item-price">${formatPrice(item.price)}</div>
            </div>
            <div class="cart-item-actions">
                <div class="quantity-control">
                    <button class="quantity-btn" onclick="updateQuantity(${item.id}, -1)">-</button>
                    <span class="quantity-value">${item.quantity}</span>
                    <button class="quantity-btn" onclick="updateQuantity(${item.id}, 1)">+</button>
                </div>
                <button class="remove-btn" onclick="removeFromCart(${item.id})">Remove</button>
            </div>
        </div>
    `).join('');
    
    if (cartTotalElement) cartTotalElement.textContent = formatPrice(getCartTotal());
}

// Kitchen Dashboard Functions
let lastOrderCount = 0;

function loadKitchenOrders() {
    fetch('api/orders.php?status=all')
        .then(response => response.json())
        .then(data => {
            renderKitchenOrders(data.orders);
            
            // Play sound for new orders
            if (lastOrderCount > 0 && data.orders.length > lastOrderCount) {
                const newOrders = data.orders.filter(o => o.status === 'new');
                if (newOrders.length > 0) {
                    playNotificationSound();
                }
            }
            lastOrderCount = data.orders.length;
        })
        .catch(error => console.error('Error:', error));
}

function renderKitchenOrders(orders) {
    const ordersGrid = document.getElementById('ordersGrid');
    if (!ordersGrid) return;
    
    if (orders.length === 0) {
        ordersGrid.innerHTML = '<div class="empty-state"><div class="empty-state-icon">📋</div><p>No orders yet</p></div>';
        return;
    }
    
    ordersGrid.innerHTML = orders.map(order => `
        <div class="order-card">
            <div class="order-card-header">
                <span class="order-id">#${order.id}</span>
                <span class="order-table">Table ${order.table_number}</span>
            </div>
            <div class="order-card-body">
                <div class="order-items-list">
                    ${order.items.map(item => `
                        <div class="order-item-row">
                            <span><span class="order-item-qty">${item.quantity}x</span> ${item.name}</span>
                            <span>${formatPrice(item.price * item.quantity)}</span>
                        </div>
                    `).join('')}
                </div>
                ${order.notes ? `<div class="order-notes"><strong>Note:</strong> ${order.notes}</div>` : ''}
                <div class="order-time">Ordered: ${order.created_at}</div>
                <div class="order-actions">
                    <button class="status-btn new ${order.status === 'new' ? 'active' : ''}" 
                            onclick="updateOrderStatus(${order.id}, 'new')" 
                            ${order.status === 'new' ? 'disabled' : ''}>New</button>
                    <button class="status-btn preparing ${order.status === 'preparing' ? 'active' : ''}" 
                            onclick="updateOrderStatus(${order.id}, 'preparing')" 
                            ${order.status === 'preparing' ? 'disabled' : ''}>Preparing</button>
                    <button class="status-btn ready ${order.status === 'ready' ? 'active' : ''}" 
                            onclick="updateOrderStatus(${order.id}, 'ready')" 
                            ${order.status === 'ready' ? 'disabled' : ''}>Ready</button>
                    <button class="status-btn completed ${order.status === 'completed' ? 'active' : ''}" 
                            onclick="updateOrderStatus(${order.id}, 'completed')" 
                            ${order.status === 'completed' ? 'disabled' : ''}>Completed</button>
                </div>
            </div>
        </div>
    `).join('');
}

function updateOrderStatus(orderId, status) {
    fetch('api/update-order.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ order_id: orderId, status: status })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadKitchenOrders();
        } else {
            alert('Error updating order status');
        }
    })
    .catch(error => console.error('Error:', error));
}

function playNotificationSound() {
    // Create a simple beep sound using Web Audio API
    try {
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();
        
        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        
        oscillator.frequency.value = 800;
        oscillator.type = 'sine';
        gainNode.gain.value = 0.3;
        
        oscillator.start();
        
        // Play multiple beeps
        setTimeout(() => {
            oscillator.frequency.value = 1000;
        }, 200);
        
        setTimeout(() => {
            oscillator.frequency.value = 800;
        }, 400);
        
        setTimeout(() => {
            oscillator.stop();
        }, 600);
    } catch (e) {
        console.log('Audio not supported');
    }
}

// Auto-refresh kitchen orders every 5 seconds
if (window.location.pathname.includes('kitchen-dashboard')) {
    setInterval(loadKitchenOrders, 5000);
}

// Admin Functions
function deleteItem(url, message) {
    if (confirm(message)) {
        window.location.href = url;
    }
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    updateCartCount();
    
    // Initialize cart page if on cart
    if (typeof renderCart === 'function' && document.getElementById('cartItems')) {
        renderCart();
    }
    
    // Initialize kitchen dashboard
    if (window.location.pathname.includes('kitchen-dashboard')) {
        loadKitchenOrders();
    }
});
