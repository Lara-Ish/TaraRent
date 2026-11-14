import re

def inspect_modals(filename):
    print('=== ' + filename + ' ===')
    with open(filename, 'r', encoding='utf-8', errors='ignore') as f:
        content = f.read()

    # Find modal blocks
    modal_blocks = re.findall(r'(<div[^>]*class=["\'][^"\']*modal-backdrop[^"\']*["\'][^>]*id=["\']([^"\']+)["\'].*?</div>\s*<!-- [^>]*-->|(?<=<div class="modal-backdrop" id=")(\w+)[\s\S]*?(?=<div class="modal-backdrop"|$))', content)
    
    # Simpler regex to match each modal backdrop and its inner buttons
    backdrops = re.finditer(r'<div[^>]*class=["\'][^"\']*modal-backdrop[^"\']*["\'][^>]*id=["\']([^"\']+)["\']', content)
    for b in backdrops:
        m_id = b.group(1)
        start_pos = b.start()
        # Find next 500 chars to locate close button
        chunk = content[start_pos:start_pos+3500]
        # find buttons in this chunk that have close or x
        btns = re.findall(r'<button[^>]*onclick=["\'][^"\']*close[^"\']*["\'][^>]*>[\s\S]*?</button>', chunk, re.IGNORECASE)
        print(f"Modal: {m_id}")
        for btn in btns:
            # truncate
            clean_btn = re.sub(r'\s+', ' ', btn)
            print(f"  Close Btn: {clean_btn[:150]}")

inspect_modals('admin_dashboard.php')
inspect_modals('tenant_portal.php')
