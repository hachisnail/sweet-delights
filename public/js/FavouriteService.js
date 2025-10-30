// /js/FavouriteService.js
class FavouriteService {
    constructor() {
        // --- UI Elements ---
        this.favSidebar = document.getElementById("favouriteSidebar");
        this.favCountEl = document.getElementById("favouriteCount");
        this.favItemsContainer = document.getElementById("favouriteItems");
        
        // --- State ---
        this.inMemoryFavs = [];
        this.isLoggedIn = window.AUTH_STATE.isLoggedIn;
        this.apiEndpoint = '/api/favourites/sync';
        this.storageKey = 'favourite';
    }

    // 1. INITIALIZATION
    async init() {
        const localFavs = JSON.parse(localStorage.getItem(this.storageKey) || "[]");
        
        if (this.isLoggedIn) {
            const serverFavs = window.AUTH_STATE.initialFavs || [];
            
            // Merge local (guest) favs with server favs
            const merged = this._mergeFavs(serverFavs, localFavs);
            this.inMemoryFavs = merged;
            
            await this.syncToServer(merged);
            localStorage.removeItem(this.storageKey);
            
        } else {
            this.inMemoryFavs = localFavs;
        }
        
        this.render();
    }

    _mergeFavs(serverFavs, localFavs) {
        const favMap = new Map();
        serverFavs.forEach(item => favMap.set(item.id, item));
        localFavs.forEach(item => favMap.set(item.id, item)); // Local wins duplicates
        return Array.from(favMap.values());
    }

    // 2. PUBLIC API METHODS
    getFavourites() {
        return [...this.inMemoryFavs];
    }
    
    isFavourite(productId) {
        return this.inMemoryFavs.some(p => p.id === productId);
    }

    toggle(product) {
        const index = this.inMemoryFavs.findIndex(p => p.id === product.id);
        
        if (index === -1) {
            // Add
            this.inMemoryFavs.push({
                id: product.id,
                name: product.name,
                image: product.image,
                price: product.price,
            });
        } else {
            // Remove
            this.inMemoryFavs.splice(index, 1);
        }
        
        this.persist();
        this.render();
        window.dispatchEvent(new Event("favouritesChanged"));
        
        return (index === -1); // Return true if added
    }

    // 3. PERSISTENCE
    persist() {
        if (this.isLoggedIn) {
            this.syncToServer(this.inMemoryFavs);
        } else {
            localStorage.setItem(this.storageKey, JSON.stringify(this.inMemoryFavs));
        }
    }

    async syncToServer(favData) {
        try {
            await fetch(this.apiEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(favData)
            });
        } catch (error) {
            console.error('Favourites sync failed:', error);
        }
    }

    // 4. UI RENDERING
    render() {
        this._renderCounts();
        this._renderSidebar();
    }
    
    _renderCounts() {
        const favs = this.inMemoryFavs;
        const faded = "bg-[#f59b62]";
        const active = "bg-[#835234]";

        if (this.favCountEl) {
            this.favCountEl.textContent = favs.length;
            this.favCountEl.classList.toggle(active, favs.length > 0);
            this.favCountEl.classList.toggle(faded, favs.length === 0);
        }
    }

    _renderSidebar() {
        const favs = this.inMemoryFavs;
        if (!this.favItemsContainer) return;
        
        this.favItemsContainer.innerHTML = "";

        if (favs.length === 0) {
            this.favItemsContainer.innerHTML = `<p class="text-gray-500">No favourites yet.</p>`;
            return;
        }

        favs.forEach((item, index) => {
            const a = document.createElement("a");
            a.href = `/products/${item.id}`;
            a.className = "flex items-center gap-3 border-b pb-2 mb-2 group";
            
            a.innerHTML = `
                <img src="${item.image || "/Assets/placeholder-item.png"}" onerror="this.onerror=null; this.src='/Assets/placeholder-item.png';" alt="${item.name}" class="w-12 h-12 object-cover rounded-lg shadow-sm shadow-gray-400">
                <div class="flex-1">
                  <p class="font-semibold text-[#835234] group-hover:underline">${item.name}</p>
                  <p class="text-sm text-gray-500">₱${item.price}</p>
                </div>
                <button class="text-red-500 hover:text-red-700">
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                  </svg>
                </button>
            `;

            a.querySelector("button").addEventListener("click", (e) => {
                e.preventDefault(); e.stopPropagation();
                // We just re-use the toggle logic
                this.toggle(item);
            });

            this.favItemsContainer.appendChild(a);
        });
    }
}