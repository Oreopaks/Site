// Static <image-slot> renderer for production.
// Renders `src` as a cover background inside a shadow root (so support.js's
// reactive style/innerHTML updates on the host can't wipe it — the editor
// component relied on the same isolation). No drag-drop, no Replace/Remove.
// The [data-kenburns] / [data-imgzoom] wrappers transform the host, and the
// shadow content scales with it, so hero zoom + hover zoom still work.
(() => {
  class ImageSlot extends HTMLElement {
    static get observedAttributes() { return ['src', 'fit', 'position']; }
    constructor() {
      super();
      const root = this.attachShadow({ mode: 'open' });
      const style = document.createElement('style');
      style.textContent = ':host{display:block;position:relative;background:#161616}' +
        '.img{position:absolute;inset:0;background-repeat:no-repeat}';
      this._img = document.createElement('div');
      this._img.className = 'img';
      root.append(style, this._img);
    }
    connectedCallback() { this._apply(); }
    attributeChangedCallback() { this._apply(); }
    _apply() {
      const src = this.getAttribute('src') || '';
      const fit = this.getAttribute('fit') || 'cover';
      const pos = this.getAttribute('position') || '50% 50%';
      const s = this._img.style;
      s.backgroundImage = src ? `url("${src}")` : '';
      s.backgroundSize = fit === 'contain' ? 'contain' : (fit === 'fill' ? '100% 100%' : 'cover');
      s.backgroundPosition = pos;
    }
  }
  if (!customElements.get('image-slot')) customElements.define('image-slot', ImageSlot);
})();
