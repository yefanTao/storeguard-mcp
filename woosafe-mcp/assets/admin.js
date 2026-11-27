(function () {
	var activeTarget = 'cursor';
	var tabs = document.querySelectorAll('.woosafe-tab-btn');
	var cursorBox = document.getElementById('woosafe-config-cursor');
	var claudeBox = document.getElementById('woosafe-config-claude');
	var copyBtn = document.getElementById('woosafe-copy-config');
	var feedback = document.getElementById('woosafe-copy-feedback');

	tabs.forEach(function (btn) {
		btn.addEventListener('click', function () {
			activeTarget = btn.getAttribute('data-config-target');
			tabs.forEach(function (b) { b.classList.remove('is-active'); });
			btn.classList.add('is-active');
			if (cursorBox) cursorBox.style.display = activeTarget === 'cursor' ? 'block' : 'none';
			if (claudeBox) claudeBox.style.display = activeTarget === 'claude' ? 'block' : 'none';
		});
	});

	if (copyBtn) {
		copyBtn.addEventListener('click', function () {
			var box = activeTarget === 'cursor' ? cursorBox : claudeBox;
			if (!box) return;
			var text = box.textContent;
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(function () {
					if (feedback) feedback.textContent = 'Copied ' + activeTarget.toUpperCase() + ' MCP JSON!';
				});
			} else {
				var ta = document.createElement('textarea');
				ta.value = text;
				document.body.appendChild(ta);
				ta.select();
				document.execCommand('copy');
				document.body.removeChild(ta);
				if (feedback) feedback.textContent = 'Copied ' + activeTarget.toUpperCase() + ' MCP JSON!';
			}
		});
	}
})();
