document.addEventListener("DOMContentLoaded", () => {
  const modal = document.getElementById("alertModal");
  
  if (!modal) {
    return;
  }

  const icon = document.getElementById("alertModalIcon");
  const title = document.getElementById("alertModalTitle");
  const message = document.getElementById("alertModalMessage");
  const buttonsContainer = document.getElementById("alertModalButtons");

  const types = {
    success: { 
      color: "bg-green-100 text-green-600", 
      icon: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-check"><path d="M20 6 9 17l-5-5"/></svg>`
    },
    error:    { 
      color: "bg-red-100 text-red-600", 
      icon: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>`
    },
    warning: { 
      color: "bg-yellow-100 text-yellow-700", 
      icon: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-alert-triangle"><path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>`
    },
    info:    { 
      color: "bg-blue-100 text-blue-600", 
      icon: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-info"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>`
    },
  };

  
  function showModal({ 
    type = "info", 
    title: modalTitle = "Notification", 
    message: modalMsg = "", 
    buttons = [{ text: "OK", variant: "primary" }] 
  } = {}) {

    const style = types[type] || types.info;

    icon.innerHTML = style.icon;
    
    icon.className = `mb-4 mx-auto w-12 h-12 flex items-center justify-center rounded-full ${style.color}`;

    title.textContent = modalTitle;
    message.textContent = modalMsg;

    buttonsContainer.innerHTML = "";
    buttons.forEach(btn => {
      const buttonEl = document.createElement("button");
      const variants = {
        primary: "bg-pink-500 hover:bg-pink-600 text-white",
        cancel: "bg-gray-200 hover:bg-gray-300 text-gray-800",
        danger: "bg-red-500 hover:bg-red-600 text-white"
      };
      buttonEl.className = `px-5 py-2 rounded-xl font-semibold transition ${variants[btn.variant] || variants.primary}`;
      buttonEl.textContent = btn.text;

      buttonEl.addEventListener("click", () => {
        modal.classList.add("hidden");
        btn.action?.();
      });

      buttonsContainer.appendChild(buttonEl);
    });

    modal.classList.remove("hidden");
  }

  modal.addEventListener("click", (e) => {
    if (e.target === modal) modal.classList.add("hidden");
  });

  window.showModal = showModal; 
});