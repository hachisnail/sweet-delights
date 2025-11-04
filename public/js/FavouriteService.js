class FavouriteService {
    constructor() {
        this.favSidebar = document.getElementById("favouriteSidebar");
        this.favCountEl = document.getElementById("favouriteCount");
        this.favItemsContainer = document.getElementById("favouriteItems");

        this.inMemoryFavs = [];
        this.isLoggedIn = window.AUTH_STATE.isLoggedIn;
        this.apiEndpoint = '/api/favourites/sync';
        this.storageKey = 'favourite';
    }

    async init() {
        let localFavs;

        try {
            const raw = localStorage.getItem(this.storageKey);
            localFavs = JSON.parse(raw || "[]");
            if (!Array.isArray(localFavs)) localFavs = [];
        } catch (error) {
            console.error("Failed to parse local favourites:", error);
            localFavs = [];
            localStorage.removeItem(this.storageKey);
        }

        if (this.isLoggedIn) {
            const serverFavs = window.AUTH_STATE.initialFavs || [];
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
        serverFavs.forEach(item => favMap.set(item.sku, item));
        localFavs.forEach(item => favMap.set(item.sku, item));
        return Array.from(favMap.values());
    }

    getFavourites() {
        return [...this.inMemoryFavs];
    }

    isFavourite(sku) {
        return this.inMemoryFavs.some(p => p.sku === sku);
    }

    toggle(product) {
        const index = this.inMemoryFavs.findIndex(p => p.sku === product.sku);

        if (index === -1) {
            this.inMemoryFavs.push({
                sku: product.sku,
                name: product.name,
                image: product.image,
                price: product.price,
            });
        } else {
            this.inMemoryFavs.splice(index, 1);
        }

        this.persist();
        this.render();
        window.dispatchEvent(new Event("favouritesChanged"));

        return index === -1;
    }

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

        favs.forEach((item) => {
            const a = document.createElement("a");
            a.href = `/products/${item.sku}`;
            a.className = "flex flex-col border-b pb-3 mb-3 group no-underline";

            a.innerHTML = `
                <div class="flex gap-3 items-center">
                    <img src="${item.image || '/Assets/placeholder-item.png'}" 
                         alt="${item.name}"
                         onerror="this.onerror=null; this.src='/Assets/placeholder-item.png';"
                         class="w-16 h-16 object-cover rounded-lg shadow-sm shadow-pink-200">
                    <div class="flex-1">
                        <p class="font-semibold text-[#835234] group-hover:underline">${item.name}</p>
                        <p class="text-sm text-gray-500">₱${item.price}</p>
                    </div>
                    <button class="removeFav text-red-500 hover:text-red-700 font-bold cursor-pointer">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                        </svg>
                    </button>
                </div>
            `;

            a.querySelector(".removeFav").addEventListener("click", (e) => {
                e.preventDefault();
                e.stopPropagation();
                window.showModal({
                    type: "error",
                    title: "Remove Favourite",
                    message: `Remove ${item.name} from your favourites?`,
                    buttons: [
                        { text: "Cancel", variant: "cancel" },
                        { text: "Remove", variant: "danger", action: () => this.toggle(item) }
                    ]
                });
            });

            this.favItemsContainer.appendChild(a);
        });
    }
}
