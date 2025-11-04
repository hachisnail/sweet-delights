class CartService {
  constructor() {
    this.cartSidebar = document.getElementById("cartSidebar");
    this.cartCountEl = document.getElementById("cartCount");
    this.cartItemsContainer = document.getElementById("cartItems");

    this.cartTotalEl = document.getElementById("cartTotal");
    this.checkoutBtn = document.getElementById("checkoutBtn");

    this.inMemoryCart = [];
    this.isLoggedIn = window.AUTH_STATE.isLoggedIn;
    this.apiEndpoint = "/api/cart/sync";
    this.storageKey = "cart";
  }

  async init() {
    let localCart;

    try {
      const raw = localStorage.getItem(this.storageKey);
      localCart = JSON.parse(raw || "[]");

      if (!Array.isArray(localCart)) {
        console.warn("Local cart was not an array, resetting.");
        localCart = [];
      }
    } catch (error) {
      console.error("Failed to parse local cart:", error);
      localCart = [];
      localStorage.removeItem(this.storageKey);
    }

    if (this.isLoggedIn) {
      const serverCart = window.AUTH_STATE.initialCart || [];
      // --- FIX: _mergeCarts logic is updated ---
      const merged = this._mergeCarts(serverCart, localCart);
      this.inMemoryCart = merged;
      await this.syncToServer(merged);
      localStorage.removeItem(this.storageKey);
    } else {
      this.inMemoryCart = localCart;
    }

    this.render();
  }

  /**
   * --- FIXED MERGE LOGIC ---
   * Merges server and local carts, adding quantities for duplicate items
   * (identified by a composite key of sku + selectedSize).
   */
  _mergeCarts(serverCart, localCart) {
    const cartMap = new Map();
    const getKey = (item) => `${item.sku}-${item.selectedSize || ''}`;

    // 1. Add all server items to the map
    serverCart.forEach((item) => {
        cartMap.set(getKey(item), item);
    });

    // 2. Merge local items
    localCart.forEach((localItem) => {
        const key = getKey(localItem);
        if (cartMap.has(key)) {
            // Item already exists, merge quantities
            const serverItem = cartMap.get(key);
            const newQty = (serverItem.quantity || 1) + (localItem.quantity || 1);
            
            // Respect stock limit (assuming serverItem has the correct stock)
            serverItem.quantity = Math.min(newQty, serverItem.stock || newQty);
        } else {
            // It's a new item (or new size), just add it
            cartMap.set(key, localItem);
        }
    });

    return Array.from(cartMap.values());
  }

  getCart() {
    return [...this.inMemoryCart];
  }

  /**
   * --- FIXED ADDITEM LOGIC ---
   * Uses a composite key (sku + size) to find existing items.
   * Respects stock limits when adding or updating quantity.
   */
  addItem(product, quantity = 1) {
    const getKey = (item) => `${item.sku}-${item.selectedSize || ''}`;
    const key = getKey(product);
    const index = this.inMemoryCart.findIndex((p) => getKey(p) === key);

    if (index === -1) {
      // Item not in cart, add it
      this.inMemoryCart.push({
        sku: product.sku,
        name: product.name,
        image: product.image,
        price: product.price,
        stock: product.stock,
        selectedSize: product.selectedSize || null,
        // Respect stock on initial add
        quantity: Math.min(quantity, product.stock),
      });
    } else {
      // Item *is* in cart, update quantity
      const existingItem = this.inMemoryCart[index];
      const newQty = existingItem.quantity + quantity;
      // Respect stock when adding
      existingItem.quantity = Math.min(newQty, existingItem.stock);
    }

    this.persist();
    this.render();
    window.dispatchEvent(new Event("cartChanged"));
  }

  /**
   * --- FIXED REMOVEITEM LOGIC ---
   * Now removes by the item's index in the array, not SKU,
   * to avoid removing the wrong item (e.g., a different size).
   */
  removeItem(index) {
    this.inMemoryCart.splice(index, 1);
    this.persist();
    this.render();
    window.dispatchEvent(new Event("cartChanged"));
  }


  _renderTotal() {
    const totalPrice = this.inMemoryCart.reduce(
      (sum, item) => sum + item.price * item.quantity,
      0
    );

    if (this.cartTotalEl) {
      this.cartTotalEl.textContent = `Total: ₱${totalPrice.toFixed(2)}`;
    }
    
    if (this.checkoutBtn) {
      // Disable the button if the cart is empty
      this.checkoutBtn.disabled = this.inMemoryCart.length === 0;
      this.checkoutBtn.classList.toggle("opacity-50", this.inMemoryCart.length === 0);
      this.checkoutBtn.classList.toggle("cursor-not-allowed", this.inMemoryCart.length === 0);
    }
  }
  /**
   * --- FIXED UPDATEQUANTITY LOGIC ---
   * Now updates by index.
   * Removes the item if quantity is 0 or less.
   * Respects stock limits.
   */
  updateQuantity(index, quantity) {
    // If quantity is 0 or less, remove the item
    if (quantity <= 0) {
      this.removeItem(index);
      return;
    }

    const item = this.inMemoryCart[index];
    if (item) {
      // Respect stock limit
      item.quantity = Math.min(quantity, item.stock);
      this.persist();
      this.render();
      window.dispatchEvent(new Event("cartChanged"));
    }
  }

  persist() {
    if (this.isLoggedIn) {
      this.syncToServer(this.inMemoryCart);
    } else {
      localStorage.setItem(this.storageKey, JSON.stringify(this.inMemoryCart));
    }
  }

  async syncToServer(cartData) {
    try {
      await fetch(this.apiEndpoint, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(cartData),
      });
    } catch (error) {
      console.error("Cart sync failed:", error);
    }
  }

  render() {
    this._renderCounts();
    this._renderSidebar();
    this._renderTotal();
  }

  _renderCounts() {
    // This logic is correct, it sums the quantities
    const totalQty = this.inMemoryCart.reduce((sum, item) => sum + item.quantity, 0);
    const faded = "bg-[#f59b62]";
    const active = "bg-[#835234]";

    if (this.cartCountEl) {
      this.cartCountEl.textContent = totalQty;
      this.cartCountEl.classList.toggle(active, totalQty > 0);
      this.cartCountEl.classList.toggle(faded, totalQty === 0);
    }
  }

  _renderSidebar() {
    const items = this.inMemoryCart;
    if (!this.cartItemsContainer) return;

    this.cartItemsContainer.innerHTML = "";

    if (items.length === 0) {
      this.cartItemsContainer.innerHTML = `<p class="text-gray-500">Your cart is empty.</p>`;
      return;
    }

    // --- FIXED RENDER LOGIC ---
    // The loop now gets the 'index'
    items.forEach((item, index) => {
      const a = document.createElement("a");
      a.href = `/products/${item.sku}`;
      a.className = "flex flex-col border-b pb-3 mb-3 group no-underline";

      a.innerHTML = `
        <div class="flex gap-3 items-center">
          <img src="${item.image}" alt="${item.name}"
               onerror="this.onerror=null; this.src='/Assets/placeholder-item.png';"
               class="w-16 h-16 object-cover rounded-lg shadow-sm shadow-pink-200">
          <div class="flex-1">
            <p class="font-semibold text-[#835234] group-hover:underline">${item.name}</p>
                        ${item.selectedSize ? `<p class="text-xs text-gray-500">Size: ${item.selectedSize}</p>` : ""}
            <p class="text-sm text-gray-500">₱${item.price}</p>
          </div>
          <button class="removeItem text-red-500 hover:text-red-700 font-bold cursor-pointer">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none"
                 stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>
              <path d="M3 6h18"/>
              <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
            </svg>
          </button>
        </div>
        <div class="flex items-center justify-between mt-3">
          <div class="flex items-center border rounded-lg overflow-hidden">
            <button class="decreaseQty px-3 py-1 bg-pink-50 hover:bg-pink-100">-</button>
            <input type="number" class="cartQty w-14 text-center border-l border-r outline-none text-sm"
                   value="${item.quantity}" min="1" max="${item.stock}">
            <button class="increaseQty px-3 py-1 bg-pink-50 hover:bg-pink-100">+</button>
          </div>
          <p class="font-semibold text-[#835234]">₱${(item.price * item.quantity).toFixed(2)}</p>
        </div>
      `;

      // Remove item - Now passes 'index'
      a.querySelector(".removeItem").addEventListener("click", (e) => {
        e.preventDefault();
        e.stopPropagation();
        window.showModal({
          type: "error",
          title: "Remove from Cart",
          message: `Remove ${item.name}${item.selectedSize ? ` (${item.selectedSize})` : ''} from your cart?`,
          buttons: [
            { text: "Cancel", variant: "cancel" },
            { text: "Remove", variant: "danger", action: () => this.removeItem(index) },
          ],
        });
      });

      // Quantity controls - Now pass 'index'
      a.querySelector(".increaseQty").addEventListener("click", (e) => {
        e.preventDefault();
        e.stopPropagation();
        this.updateQuantity(index, item.quantity + 1);
      });

      a.querySelector(".decreaseQty").addEventListener("click", (e) => {
        e.preventDefault();
        e.stopPropagation();
        // This will now correctly remove the item if quantity reaches 0
        this.updateQuantity(index, item.quantity - 1);
      });

      a.querySelector(".cartQty").addEventListener("change", (e) => {
        const value = parseInt(e.target.value) || 0; // Default to 0 to trigger removal
        this.updateQuantity(index, value);
      });

      this.cartItemsContainer.appendChild(a);
    });
  }
}