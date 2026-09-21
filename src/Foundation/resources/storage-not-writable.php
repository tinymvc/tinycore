<div
    style="max-width:560px;margin:40px auto;padding:24px;background:#fff;border:1px solid #f1c0c5;border-left:4px solid #dc3545;border-radius:8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;box-shadow:0 2px 8px rgba(0,0,0,.06);">
    <h2 style="margin:0 0 8px;font-size:18px;color:#842029;">Storage directory not writable</h2>
    <p style="margin:0 0 16px;font-size:14px;line-height:1.5;color:#495057;">
        Make sure <code>storage</code> and <code>bootstrap/cache</code> are writable, then reload this page.
    </p>

    <div
        style="display:flex;align-items:center;gap:8px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:8px 10px;">
        <code id="fix-cmd"
            style="flex:1;font-size:13px;color:#212529;overflow-x:auto;white-space:nowrap;">chmod -R 775 storage bootstrap/cache</code>
        <button id="copy-btn" type="button"
            onclick="navigator.clipboard.writeText(document.getElementById('fix-cmd').innerText).then(function(){var b=document.getElementById('copy-btn');b.innerText='Copied ✓';setTimeout(function(){b.innerText='Copy'},1500)})"
            style="padding:6px 12px;font-size:13px;color:#fff;background:#dc3545;border:0;border-radius:5px;cursor:pointer;">Copy</button>
    </div>

    <p style="margin:12px 0 0;font-size:12px;color:#6c757d;">
        Still failing? Also set the owner: <code>chown -R www-data:www-data storage bootstrap/cache</code>
    </p>

    <button type="button" onclick="location.reload()"
        style="margin-top:16px;padding:8px 16px;font-size:14px;color:#842029;background:transparent;border:1px solid #dc3545;border-radius:5px;cursor:pointer;">Retry</button>
</div>