window.openMapsMenu = function(city) {
    const menu = document.getElementById('maps-menu');
    if (!menu) {
        return;
    }

    const eslamshahrLinks = document.getElementById('maps-links-eslamshahr');
    const qazvinLinks = document.getElementById('maps-links-qazvin');

    if (eslamshahrLinks) {
        eslamshahrLinks.classList.add('hidden');
    }
    if (qazvinLinks) {
        qazvinLinks.classList.add('hidden');
    }

    const selectedLinks = document.getElementById(`maps-links-${city}`);
    const buttons = document.querySelectorAll('.city-toggle');

    function showSelected() {
        if (eslamshahrLinks) eslamshahrLinks.classList.add('hidden');
        if (qazvinLinks) qazvinLinks.classList.add('hidden');
        if (selectedLinks) selectedLinks.classList.remove('hidden');
        buttons.forEach(button => {
            button.classList.toggle('active', button.dataset.city === city);
        });
        menu.classList.remove('hidden');
    }

    // If menu is already open, briefly close then reopen to indicate change
    if (!menu.classList.contains('hidden')) {
        menu.classList.add('hidden');
        setTimeout(showSelected, 180);
    } else {
        showSelected();
    }
};

window.setMapsCity = window.openMapsMenu;

function openLoginModal() {
    const modal = document.getElementById('login-modal');
    if (modal) {
        modal.classList.remove('hidden');
    }
}

window.toggleMobileMenu = function(e) {
    if (e && e.stopPropagation) e.stopPropagation();
    const nav = document.querySelector('.site-nav');
    if (!nav) return;
    nav.classList.toggle('mobile-open');
};

// Close mobile nav when any nav link is clicked
(function attachNavLinkHandlers(){
    const nav = document.querySelector('.site-nav');
    if (!nav) return;
    const links = nav.querySelectorAll('a');
    links.forEach(link => {
        link.addEventListener('click', function() {
            nav.classList.remove('mobile-open');
            // also hide maps menu if open
            const mapsMenu = document.getElementById('maps-menu');
            if (mapsMenu) mapsMenu.classList.add('hidden');
        });
    });
})();

function closeLoginModal() {
    const modal = document.getElementById('login-modal');
    if (modal) {
        modal.classList.add('hidden');
    }
}

// بستن منو یا مودال هنگام کلیک خارج از آن
document.addEventListener('click', function(event) {
    const clickedOnBtn = !!event.target.closest('.btn-maps');
    const menu = document.getElementById('maps-menu');
    const modal = document.getElementById('login-modal');
    const modalCard = document.querySelector('.modal-card');

    if (menu && !clickedOnBtn && !menu.contains(event.target)) {
        menu.classList.add('hidden');
    }

    // close mobile nav when clicking outside
    const nav = document.querySelector('.site-nav');
    const clickedOnNav = !!event.target.closest('.site-nav');
    const clickedOnMenuToggle = !!event.target.closest('.menu-toggle');
    if (nav && !clickedOnNav && !clickedOnMenuToggle) {
        nav.classList.remove('mobile-open');
    }

    if (modal && !modal.classList.contains('hidden') && modalCard && !modalCard.contains(event.target)) {
        closeLoginModal();
    }
});
