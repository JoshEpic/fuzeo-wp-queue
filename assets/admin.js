(function () {
  var root = document.querySelector('.fuzeo-queue-admin');
  if (!root) {
    return;
  }
  var timer = null;
  function poll() {
    if (document.hidden) {
      return;
    }
    root.setAttribute('data-polled', new Date().toISOString());
  }
  function start() {
    if (timer) {
      window.clearInterval(timer);
    }
    timer = window.setInterval(poll, 10000);
  }
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      if (timer) {
        window.clearInterval(timer);
        timer = null;
      }
      return;
    }
    start();
  });
  start();
})();
