// /js/CartService.js
class CartService {
    constructor() {
        // --- UI Elements ---
        this.cartSidebar = document.getElementById("cartSidebar");
        this.cartCountEl = document.getElementById("cartCount");
        this.cartItemsContainer = document.getElementById("cartItems");
        this.cartTotalEl = document.getElementById("cartTotal");
        
        // --- State ---
        this.inMemoryCart = [];
        this.isLoggedIn = window.AUTH_STATE.isLoggedIn;
        this.apiEndpoint = '/api/cart/sync';
        this.storageKey = 'cart';
    }

    // 1. INITIALIZATION
    async init() {
        const localCart = JSON.parse(localStorage.getItem(this.storageKey) || "[]");
        
        if (this.isLoggedIn) {
            const serverCart = window.AUTH_STATE.initialCart || [];
            
            // We have a logged-in user. Merge local (guest) cart with server cart.
            const merged = this._mergeCarts(serverCart, localCart);
            this.inMemoryCart = merged;
            
            // Sync the merged cart back to the server immediately
            await this.syncToServer(merged);
            
            // Clear the now-stale local cart
            localStorage.removeItem(this.storageKey);
            
        } else {
            // Guest user. Just load from localStorage.
            this.inMemoryCart = localCart;
        }
        
        this.render();
    }

    _mergeCarts(serverCart, localCart) {
        const cartMap = new Map();

        // 1. Add all server items first
        serverCart.forEach(item => {
            const key = `${item.id}-${item.selectedSize || ''}`;
            cartMap.set(key, item);
        });

        // 2. Merge local items
        localCart.forEach(localItem => {
            const key = `${localItem.id}-${localItem.selectedSize || ''}`;
            if (cartMap.has(key)) {
                // Item already exists, merge quantities
                const serverItem = cartMap.get(key);
                serverItem.quantity = Math.min(
                    (serverItem.quantity || 1) + (localItem.quantity || 1),
                    serverItem.stock // Don't exceed stock
                );
            } else {
                // New item from local cart, add it
                cartMap.set(key, localItem);
            }
        });
        
        return Array.from(cartMap.values());
    }

    // 2. PUBLIC API METHODS
    getCart() {
        return [...this.inMemoryCart]; // Return a copy
    }

    addItem(itemToAdd, quantity) {
        const requested = quantity || 1;
        const key = `${itemToAdd.id}-${itemToAdd.selectedSize || ''}`;
        
        const existingItem = this.inMemoryCart.find(
            p => `${p.id}-${p.selectedSize || ''}` === key
        );

        if (existingItem) {
            existingItem.quantity = Math.min(
                existingItem.quantity + requested, 
                itemToAdd.stock // Use stock from the item being added (it's fresher)
            );
        } else {
            this.inMemoryCart.push({
                ...itemToAdd,
                quantity: requested
            });
        }
        
        this.persist();
        this.render();
        window.dispatchEvent(new Event("cartChanged"));
    }

    removeItem(index) {
        this.inMemoryCart.splice(index, 1);
        this.persist();
        this.render();
        window.dispatchEvent(new Event("cartChanged"));
    }

    updateQuantity(index, newQuantity, stock) {
        if (newQuantity <= 0) {
            this.removeItem(index);
            return;
        }
        const qty = Math.min(newQuantity, stock);
        this.inMemoryCart[index].quantity = qty;
        this.persist();
        this.render();
        window.dispatchEvent(new Event("cartChanged"));
    }

    clearCart() {
        this.inMemoryCart = [];
        this.persist();
        this.render();
        window.dispatchEvent(new Event("cartChanged"));
    }

    // 3. PERSISTENCE
    persist() {
        if (this.isLoggedIn) {
            // "Fire and forget" async sync
            this.syncToServer(this.inMemoryCart);
        } else {
            // Save to localStorage for guests
            localStorage.setItem(this.storageKey, JSON.stringify(this.inMemoryCart));
        }
    }

    async syncToServer(cartData) {
        try {
            await fetch(this.apiEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(cartData)
            });
        } catch (error) {
            console.error('Cart sync failed:', error);
            // Add logic to retry or notify user
        }
    }

    // 4. UI RENDERING (Moved from public.twig)
    render() {
        this._renderCounts();
        this._renderSidebar();
    }
    
    _renderCounts() {
        const cart = this.inMemoryCart;
        const faded = "bg-[#f59b62]";
        const active = "bg-[#835234]";

        if (this.cartCountEl) {
            this.cartCountEl.textContent = cart.length;
            this.cartCountEl.classList.toggle(active, cart.length > 0);
            this.cartCountEl.classList.toggle(faded, cart.length === 0);
        }
    }

    _renderSidebar() {
        const cart = this.inMemoryCart;
        if (!this.cartItemsContainer) return;
        
        this.cartItemsContainer.innerHTML = "";

        if (cart.length === 0) {
            this.cartItemsContainer.innerHTML = `<p class="text-gray-500">Your cart is empty.</p>`;
            if (this.cartTotalEl) this.cartTotalEl.textContent = `Total: ₱0.00`;
            return;
        }

        let total = 0;
        cart.filter(Boolean).forEach((item, index) => {
            total += parseFloat(item.price) * item.quantity;
            
            const a = document.createElement("a");
            a.href = `/products/${item.id}`;
            a.className = "flex flex-col border-b pb-3 mb-3 group no-underline";

            // (This innerHTML is copied directly from your public.twig)
            a.innerHTML = `
              <div class="flex gap-3 items-center">
                <img src="${item.image}" alt="${item.name}" class="w-16 h-16 object-cover rounded-lg shadow-sm shadow-pink-200">
                <div class="flex-1">
                  <p class="font-semibold text-[#835234] group-hover:underline">${item.name}</p>
                  ${item.selectedSize ? `<p class="text-xs text-gray-500">Size: ${item.selectedSize}</p>` : ""}
                  <p class="text-sm text-gray-500">₱${item.price}</p>
                </div>
                <button class="removeItem text-red-500 hover:text-red-700 font-bold cursor-pointer">
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                  </svg>
                </button>
              </div>
              <div class="flex items-center justify-between mt-2">
                <div class="flex items-center border rounded-lg overflow-hidden">
                  <button class="decreaseQty px-3 py-1 bg-pink-100 hover:bg-pink-200">−</button>
                  <input type="number" class="cartQty w-16 text-center border-l border-r outline-none" value="${item.quantity}" min="1" max="${item.stock}">
                  <button class="increaseQty px-3 py-1 bg-pink-100 hover:bg-pink-200">+</button>
                </div>
              </div>
            `;
            
            // --- Event Listeners (Must be bound to 'this') ---
            a.querySelector(".removeItem").addEventListener("click", (e) => {
                e.preventDefault(); e.stopPropagation();
                window.showModal({
                    type: "error",
                    title: "Remove from Cart",
                    message: `Remove ${item.name}${item.selectedSize ? " (" + item.selectedSize + ")" : ""} from your cart?`,
                    buttons: [
                        { text: "Cancel", variant: "cancel" },
                        { text: "Remove", variant: "danger", action: () => this.removeItem(index) }
                    ]
                });
            });

            a.querySelector(".decreaseQty").addEventListener("click", (e) => {
                e.preventDefault(); e.stopPropagation();
                this.updateQuantity(index, item.quantity - 1, item.stock);
            });

            a.querySelector(".increaseQty").addEventListener("click", (e) => {
                e.preventDefault(); e.stopPropagation();
                if (item.quantity < item.stock) {
                    this.updateQuantity(index, item.quantity + 1, item.stock);
                } else {
                    window.showModal({
                        type: "warning",
                        title: "Stock Limit",
                        message: `You’ve reached the stock limit for ${item.name}${item.selectedSize ? " (" + item.selectedSize + ")" : ""}.`,
                        buttons: [{ text: "OK", variant: "cancel" }]
                    });
                }
            });

            this.cartItemsContainer.appendChild(a);
        });

        if (this.cartTotalEl) this.cartTotalEl.textContent = `Total: ₱${total.toFixed(2)}`;
    }
}