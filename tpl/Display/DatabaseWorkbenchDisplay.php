<?php
	$containerId = 'databaseworkbench_' . bin2hex(random_bytes(6));
	$config = [
		'containerId' => $containerId,
		'endpoint' => $this->_['endpoint'],
		'csrf' => $this->_['csrf'],
		'pageSize' => $this->_['page_size'],
		'strings' => is_array($this->_['translations'] ?? null) ? $this->_['translations'] : [],
	];
	$cssUrl = ($this->_['resolve'])('plugin/DatabaseWorkbench/assets/databaseworkbench/databaseworkbench.css');
	$jsUrl = ($this->_['resolve'])('plugin/DatabaseWorkbench/assets/databaseworkbench/databaseworkbench.js');
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8'); ?>" />
<div
	id="<?php echo htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'); ?>"
	class="databaseworkbench"
	data-title="<?php echo htmlspecialchars($this->_['title'], ENT_QUOTES, 'UTF-8'); ?>"
></div>
<script>
(function() {
	var config = <?php echo json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
	var scriptUrl = <?php echo json_encode($jsUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

	function mount() {
		if (window.DatabaseWorkbench && typeof window.DatabaseWorkbench.mount === 'function') {
			window.DatabaseWorkbench.mount(config);
		}
	}

	if (window.DatabaseWorkbench) {
		mount();
		return;
	}

	window.__databaseWorkbenchLoader = window.__databaseWorkbenchLoader || new Promise(function(resolve, reject) {
		var script = document.createElement('script');
		script.src = scriptUrl;
		script.async = true;
		script.onload = resolve;
		script.onerror = reject;
		document.head.appendChild(script);
	});

	window.__databaseWorkbenchLoader.then(mount).catch(function() {
		var target = document.getElementById(config.containerId);
		if (target) target.textContent = <?php echo json_encode((string)(($this->_['translations']['asset_load_failed'] ?? '') ?: 'Unable to load DatabaseWorkbench assets.'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
	});
})();
</script>
