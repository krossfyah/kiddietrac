/* Bootstrap when DOM is ready */
document.addEventListener('DOMContentLoaded', () => {
  if (window.KT && window.KT.Shell) {
    window.KT.Shell.startApp();
  } else {
    console.error('KT.Shell not loaded — check script order');
  }
});
