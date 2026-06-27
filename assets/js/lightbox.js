// Lightbox ساده
class SimpleLightbox {
    constructor() {
        this.currentIndex = 0;
        this.images = [];
        this.init();
    }

    init() {
        const gallery = document.querySelector('.lightbox-gallery');
        if (!gallery) return;

        this.images = Array.from(gallery.querySelectorAll('.lightbox-item img'));
        this.createOverlay();
        this.attachEventListeners();
    }

    createOverlay() {
        if (document.querySelector('.lightbox-overlay')) return;

        const overlay = document.createElement('div');
        overlay.className = 'lightbox-overlay';
        overlay.innerHTML = `
            <button class="lightbox-close">✕</button>
            <button class="lightbox-nav lightbox-prev">‹</button>
            <div class="lightbox-content">
                <img class="lightbox-img" src="" alt="">
                <div class="lightbox-caption"></div>
            </div>
            <button class="lightbox-nav lightbox-next">›</button>
        `;
        document.body.appendChild(overlay);

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) this.close();
        });

        overlay.querySelector('.lightbox-close').addEventListener('click', () => this.close());
        overlay.querySelector('.lightbox-prev').addEventListener('click', () => this.prev());
        overlay.querySelector('.lightbox-next').addEventListener('click', () => this.next());

        document.addEventListener('keydown', (e) => {
            if (!overlay.classList.contains('active')) return;
            if (e.key === 'Escape') this.close();
            if (e.key === 'ArrowLeft') this.prev();
            if (e.key === 'ArrowRight') this.next();
        });
    }

    attachEventListeners() {
        this.images.forEach((img, index) => {
            img.parentElement.addEventListener('click', () => this.open(index));
        });
    }

    open(index) {
        this.currentIndex = index;
        const overlay = document.querySelector('.lightbox-overlay');
        overlay.classList.add('active');
        this.showImage();
    }

    close() {
        const overlay = document.querySelector('.lightbox-overlay');
        overlay.classList.remove('active');
    }

    next() {
        this.currentIndex = (this.currentIndex + 1) % this.images.length;
        this.showImage();
    }

    prev() {
        this.currentIndex = (this.currentIndex - 1 + this.images.length) % this.images.length;
        this.showImage();
    }

    showImage() {
        const img = this.images[this.currentIndex];
        const overlay = document.querySelector('.lightbox-overlay');
        overlay.querySelector('.lightbox-img').src = img.src;
        overlay.querySelector('.lightbox-caption').textContent = img.alt || '';
    }
}

// شروع هنگام بارگذاری صفحه
document.addEventListener('DOMContentLoaded', () => {
    new SimpleLightbox();
});
