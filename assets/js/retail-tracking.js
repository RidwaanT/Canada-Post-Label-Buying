document.addEventListener('click', async function (event) {
  const button = event.target.closest('.wlp-rt-copy');
  if (!button) return;
  const number = button.parentElement.querySelector('code');
  if (!number) return;
  try { await navigator.clipboard.writeText(number.textContent.trim()); button.textContent = 'Copied'; }
  catch { button.textContent = 'Select number to copy'; }
});
