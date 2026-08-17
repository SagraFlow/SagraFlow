/*
 * iOS applies :active styles only on documents that listen for touch events -
 * a Safari quirk, not a rule anyone would guess. Without this listener the
 * pressed state on every till key is dead on an iPad while working perfectly
 * on a desktop browser, which is the worst way for it to fail.
 */
document.addEventListener('touchstart', () => {}, { passive: true });
