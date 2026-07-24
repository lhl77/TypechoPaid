(function () {
  // Mirages 主题的亮/暗模式完全由插件自身的 applyThemeMode() 驱动
  // （data-mode / data-mode-switch → tp-paid-mode-dark / tp-paid-mode-light）。
  // 本脚本仅标记主题就绪，不干预插件全局的模式判断。
  var nodes = document.querySelectorAll('.tp-paid-theme-mirages');
  for (var i = 0; i < nodes.length; i++) {
    nodes[i].setAttribute('data-theme-ready', '1');
  }
})();
